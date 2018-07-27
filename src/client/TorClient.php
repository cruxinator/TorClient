<?php
namespace cruxinator\TorClient\client;
use cruxinator\TorClient\Lib\BigInteger;
use cruxinator\TorClient\Lib\Logger;
use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

class TorClient
{
    /**
     * @var Logger
     */
    private static $clientlog;

    /**
     * @var \Workerman\Connection\ConnectionInterface
     */
    private $client;
    /**
     * @var String
     */
    private $directory;
    /**
     * @var String[3]
     */
    private $IP = [];// String[3];
    /**
     * @var String[3]
     */
    private $E = [];//String[3];
    /**
     * @var String[3]
     */
    private $N = [];//String[3];
    /**
     * @var String
     */
    private $FinalIP;
    /**
     * @var String
     */
    private $DirIP = "127.0.0.1";
    /**
     * @var int
     */
    private $DirPort = 9090;

    function __construct()
    {
        self::$clientlog = Logger::getLogger("client");
        self::$clientlog->info("Tor Client initialized.");
        try {
            //connect to directorey
            $this->client = new AsyncTcpConnection('tcp://'.$this->DirIP .':'. $this->DirPort);
            /**
             * @param \Workerman\Connection\ConnectionInterface  $remote_connection
             */
            $this->client->onConnect = function($remote_connection) {

                $remote_connection->send("1");
            };
            //$this->client = new Socket($this->DirIP, $this->DirPort);
        } catch (\Exception $ex) {
            self::$clientlog->severe("Can't connect to the directory. Exiting program...");
            exit(0);
        }

    }

    public static function main()
    {
        self::$clientlog->info("Tor Client running.");
        $object = new TorClient();
        $object->loginit();
        $object->FinalIP = "192.168.0.1";
        $object->DirData();
        //get request from client
        print("Enter data :");
        $message = readline();
        self::$clientlog->info("Data to be sent received.");
        //encrypt thrice
        $encrypted = $object->makeOnion(unpack('C*', $message));
        //sending to bridge node
        $object->torouter1($encrypted);

    }

    private function loginit()
    {
        try {
            $logFile = fopen("./TorClient.log", "w");
            self::$clientlog->addHandler($logFile);

        } catch (\Exception $ex) {
            self::$clientlog->severe("Exception raised in creating log file. Exiting program.");
        }
    }

    /**
     * @return String
     */
    private function DirData()
    {
        self::$clientlog->info("Fetching data from Tor Directory.");
        /**
         * @param \Workerman\Connection\ConnectionInterface $connection
         * @param [] $buffer
         */
        $this->client->onMessage = function($connection, $buffer) {
            $buffer_str = $this->bytesToString($buffer);
            $this->directory = $buffer_str;
            $this->splitString($this->directory);
            $connection->close();

        };
        $this->client->connect();
        self::$clientlog->info("Data fetching finished.");
        return $this->directory;
    }

    //split ip

    /**
     * @param string $directory
     */
    private function splitString($directory)
    {
        $this->directory = $directory;
        $splitData = explode("/", $directory);
        $j = 1;
        for ($i = 0; $i < 3; $i++) {
            //print(count($splitData) . "---"+$i);
            $this->IP[$i] = $splitData[$j++];
            print($this->IP[$i]);
            $this->E[$i] = $splitData[$j++];
            print("E=" . $this->E[$i]);
            $this->N[$i] = $splitData[$j++];
            print("N=" . $this->N[$i]);
        }
    }

    /**
     * @param [] $message
     * @return []
     */
    private function makeOnion($message)
    {
        self::$clientlog->info("Data encryption process initiated.");
        $peel2 = $this->makeCell(
            $this->makeCell(
                $this->makeCell(
                    $message,
                    $this->FinalIP,
                    2
                ),
                $this->IP[2],
                1),
            $this->IP[1],
            0
        );
        return $peel2;
    }

    /**
     * @param [] $data
     * @param string $IP
     * @param int $i
     * @return []
     */
    private function makeCell($data, $IP, $i)
    {
        $p = $IP . "::" . implode(array_map("chr", $data));
        print($i . "th peel: " . $p);
        $peel = unpack('C*', $p);
        $encPeel = unpack('C*',$this->encrypt($peel, $i));
        print("encrypted peel in bytes--> " . $this->bytesToString($encPeel));
        print("len of encrypted peel in bytes--> " . count($encPeel));
        return $encPeel;
    }

    /**
     * @param [] $peel
     * @param int $i
     * @return []
     */
    private function encrypt($peel, $i)
    {
        $e = new BigInteger($this->E[$i]);
        $n = new BigInteger($this->N[$i]);
        $enc = (new BigInteger($peel)) -> powMod($e, $n) -> toBytes();
        return $enc;
    }

    /**
     * @param byte[]
     * @return string
     */
    private static function bytesToString($e)
    {
        if(!is_array($e)){
            return $e;
        }
        //return implode(array_map("chr", $e));
        $test = "";
        foreach ($e as $b) {
            $test += chr($b);
        }
        return $test;
    }

    /**
     * @param [] $message_router1
     */
    private function torouter1($message_router1)
    {
        try {
            self::$clientlog->info("Data being sent through Proxy Routers.");
            $client = new AsyncTcpConnection('tcp://'.$this->IP[2] .':'. 9091);
            /**
             * @param \Workerman\Connection\ConnectionInterface $remote_connection
             */
            $client->onConnect = function($remote_connection) use($message_router1)
            {
                $remote_connection->send(count($message_router1));
                $remote_connection->send($message_router1);
                $remote_connection->close();
            };
            $client->connect();
        } catch (\Exception $ex) {
            self::$clientlog->severe("Data couldn't be sent to the Router. Exiting Program");
        }
    }
}
