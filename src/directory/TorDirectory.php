<?php
namespace cruxinator\TorClient\directory;
use cruxinator\TorClient\Lib\BigInteger;
use cruxinator\TorClient\Lib\Logger;

class TorDirectory
{
    /**
     * @var Logger
     */
    private static $dirlog;
    /**
     * @var DataInputStream
     */
    private $req;
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
     * @var ServerSocket
     */
    private static $directory;

    function __construct()
    {
        $this->dirlog = Logger::getLogger("directory");
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
            self::$directory = new ServerSocket($tordir->DirPort, 10);
            while (true) {
                $tordir->connect();                     //connect to node

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
            $incoming = new Socket();
            try {
                $incoming = self::$directory->accept();                                  //accept incoming socket request
            } catch (\Exception $ex) {
                self::$dirlog->warning("Couldn't connect to the node.");
                return;
            }
            self::$dirlog->info("Node connected.");
            $this->req = new DataInputStream($incoming->getInputStream());
            $this->input = $this->req->readUTF();                                               //read input from node
            //System.out.println(input);
            $this->token = split("/", $this->input);
            switch ($this->token[0])                                                    //check for header
            {
                case "0":                                                       // if header=0 then it is a router
                    self::$dirlog->info("Node identified as Router.");
                    $this->id = 0;
                    $this->router($incoming);
                    break;
                case "1":
                    self::$dirlog->info("Node identified as Client.");                   //if header=1 then it is a client
                    $this->id = 1;
                    $this->client($incoming);
                    break;
                default:
                    self::$dirlog->warning("Node can't be identified. Closing connection...");
                    $this->id = 2;
            }
            $incoming->close();
        } catch (\Exception $ex) {
            self::$dirlog->severe("Couldn't receive data from Node.");
        }
    }

    /**
     * @param Socket $incoming
     */
    private function router($incoming)
    {
        self::$dirlog->info("Router operations initiated.");
        $this->IP[$this->count] = "" . $incoming->getInetAddress();                                       //get ip address of the router node
        $track = 1;
        for ($i = 0; $i < 3; $i++) {
            self::$dirlog->info("" . $track);
            $this->RSA[$this->count][0][$i] = $this->token[$track++]; //E                                                   // get base of RSA key of the router node
            $this->RSA[$this->count][1][$i] = $this->token[$track++];    //N                                                  //get exponent of RSA key of the router node
            self::$dirlog->info("\n=====>> IP=" . $this->IP[$this->count] .
                "\nE[" . $i . "] = " . $this->RSA[$this->count][0][$i] .
                "\nN[" . $i . "] = " . $this->RSA[$this->count][1][$i] . "\n");
        }
        $this->status[$this->count++] = true;                                                         //mark the router online
        self::$dirlog->info("Number of routers online : " . $this->count);
    }

    /**
     * @param Socket $incoming
     */
    private function client($incoming)
    {
        self::$dirlog->info("Client operations initiated.");
        $router = [];// int[3];
        $router[0] = rand(0, $this->count); //(count should be excluded... is it?)
        print($router[0]);
        while (($router[1] = rand(0, $this->count)) == $router[0]) {
            print("try" . $router[1]);
        }
        print($router[1]);
        while ((($router[2] = rand(0, $this->count)) == $router[1]) || ($router[2] == $router[0])) {
            print("try" . $router[2]);
        }
        print($router[2]);
        $metadata = "";
        $key = 2;
        foreach ($router as $node) {
            $metadata .= $this->IP[$node] . "/" . $this->RSA[$node][0][$key] . "/" . $this->RSA[$node][1][$key--];
        }
        print($metadata);
        try {
            $response = new DataOutputStream($incoming->getOutputStream());
            $response->writeUTF($metadata);
            self::$dirlog->info("Data sent to Client " . $incoming->getInetAddress());
        } catch (\Exception $ex) {
            self::$dirlog->warning("Data couldn't be sent to Client: " . $incoming->getInetAddress());
        }
    }
}