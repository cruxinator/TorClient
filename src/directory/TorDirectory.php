<?php

namespace cruxinator\TorClient\directory;

use cruxinator\TorClient\lib\Logger;
use \Workerman\Worker;


class TorDirectory
{
    /**
     * @var Logger
     */
    private static $dirlog;
    /**
     * @var String
     */
    private $input;
    /**
     * @var String[]
     */
    private $IP;
    /**
     * @var String[]
     */
    private $token;
    /**
     * @var String[][][]
     */
    private $RSA; //String[][][]
    /**
     * @var boolean[]
     */
    private $status; // bool[]
    /**
     * @var int
     */
    private $N; //int
    /**
     * @var int
     */
    private $count; //int
    /**
     * @var int
     */
    private $DirPort = 9090; //int
    /**
     * @var int
     */
    private $id; //int
    /**
     * @var Worker
     */
    private static $directory;

    function __construct()
    {
        self::$dirlog = Logger::getLogger("directory");
        self::$dirlog->info("Tor Directory initialized.");
        $this->input = "";
        $this->id = 3;
        $this->N = 50;
        $this->IP = []; //String[$this->N];
        $this->RSA = [];//String[$this->N][2][3];
        $this->RSA = array_fill(0, $this->N, []);
        $this->status = [];// boolean[$this->N];
        $this->count = 0;
        self::$dirlog->info("Number of routers online : " . $this->count);
    }


    public static function main()
    {
        try {
            self::$dirlog->info("Tor Directory running.");
            $tordir = new TorDirectory(); //create object
            $tordir->loginit();
            //self::$directory = new ServerSocket($tordir->DirPort, 10);
            self::$directory = new Worker('tcp://0.0.0.0:' . $tordir->DirPort);
            while (true) {
                //connect to node
                $tordir->connect();

            }
        } catch (\Exception $ex) {
            self::$dirlog->severe("Directory Port busy. Exiting program.");
        }
    }


    private function loginit()
    {
        try {
            $logFile = fopen("./TorDir.log", "w");
            self::$dirlog->addHandler($logFile);

        } catch (\Exception $ex) {
            self::$dirlog->severe("Exception raised in creating log file. Exiting program.");
        }
    }

    private function connect()
    {
        try {
            self::$dirlog->info("Waiting to connect");
            /**
             * @param \Workerman\Connection\ConnectionInterface  $incoming
             */
            self::$directory->onConnect= function($incoming)
            {
                self::$dirlog->info("Node connected from " . $incoming->getRemoteIp());
            };
            /**
             * @param \Workerman\Connection\ConnectionInterface $connection
             * @param [] $buffer
             */
            self::$directory->onMessage = function($connection, $buffer) {
                self::$dirlog->info("Node message received.");
                //read input from node
                $this->input = mb_convert_encoding(implode(array_map("chr", $buffer)), 'utf-8');//$this->req->readUTF();
                $this->token = explode ("/", $this->input);

                //check for header
                switch ($this->token[0])
                {
                    // if header=0 then it is a router
                    case "0":
                        self::$dirlog->info("Node identified as Router.");
                        $this->id = 0;
                        $this->router($connection);
                        break;
                    //if header=1 then it is a client
                    case "1":
                        self::$dirlog->info("Node identified as Client.");
                        $this->id = 1;
                        $this->client($connection);
                        break;
                    default:
                        self::$dirlog->warning("Node can't be identified. Closing connection...");
                        $this->id = 2;
                }
                $connection->close();
            };
        } catch (\Exception $ex) {
            self::$dirlog->severe("Couldn't receive data from Node.");
        }
    }

    /**
     * @param \Workerman\Connection\ConnectionInterface $incoming
     */
    private function router($incoming)
    {
        self::$dirlog->info("Router operations initiated.");
        //get ip address of the router node
        $this->IP[$this->count] = "" . $incoming->getRemoteIp();
        $track = 1;
        for ($i = 0; $i < 3; $i++) {
            self::$dirlog->info("" . $track);
            // get base of RSA key of the router node
            $this->RSA[$this->count][0][$i] = $this->token[$track++]; //E
            //get exponent of RSA key of the router node
            $this->RSA[$this->count][1][$i] = $this->token[$track++];    //N
            self::$dirlog->info("\n=====>> IP=" . $this->IP[$this->count] .
                                "\nE[" . $i . "] = " . $this->RSA[$this->count][0][$i] .
                                "\nN[" . $i . "] = " . $this->RSA[$this->count][1][$i] . "\n");
        }
        //mark the router online
        $this->status[$this->count++] = true;
        self::$dirlog->info("Number of routers online : " . $this->count);
    }

    /**
     * @param \Workerman\Connection\ConnectionInterface $incoming
     */
    private function client($incoming)
    {
        self::$dirlog->info("Client operations initiated.");
        $router = [];// int[3];
        // assumes that this->count is at least 3
        $select = $this->randPerm($this->count);
        $router[0] = $select[0];
        $router[1] = $select[1];
        $router[2] = $select[2];

        $metadata = "";
        $key = 2;
        foreach ($router as $node) {
            $metadata .= $this->IP[$node] . "/" . $this->RSA[$node][0][$key] . "/" . $this->RSA[$node][1][$key--];
        }
        print($metadata);
        try {
            $incoming->send($metadata);
            self::$dirlog->info("Data sent to Client " . $incoming->getRemoteIp());
        } catch (\Exception $ex) {
            self::$dirlog->warning("Data couldn't be sent to Client: " . $incoming->getRemoteIp());
        }
    }
    
    private function randPerm($numEntries)
    {
        $payload = [];
        for ($i = 0; $i < $numEntries; $i++) {
            $payload[] = mt_rand();
        }
        asort($payload);
        return array_keys($payload);
    }
}
