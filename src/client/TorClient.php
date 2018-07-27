<?php
namespace cruxinator\TorClient\client;
use cruxinator\TorClient\Lib\BigInteger;
use cruxinator\TorClient\Lib\Logger;
class TorClient
{
    /**
     * @var Logger
     */
    private static $clientlog;
    /**
     * @var DataInputStream
     */
    private $din;
    /**
     * @var DataOutputStream
     */
    private $dout;
    /**
     * @var BufferedReader
     */
    private $br;
    /**
     * @var Socket
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
    private $DirIP = "192.168.0.111";
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
            $this->client = new Socket($this->DirIP, $this->DirPort);
            $this->dout = new DataOutputStream($this->client->getOutputStream());
            $this->dout->writeUTF("1");
        } catch (\Exception $ex) {
            self::$clientlog->severe("Can't connect to the directory. Exiting program...");
            exit(0);
        }

    }

    public static function main($args)
    {
        self::$clientlog->info("Tor Client running.");
        $object = new TorClient();
        $object->loginit();
        $object->FinalIP = "192.168.0.1";
        $dir = $object->DirData();
        $object->splitString($dir);
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
            self::$clientlog->addHandler(logFile);

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
        $this->din = new DataInputStream($this->client->getInputStream());
        $this->directory = $this->din -> readUTF();
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
        $splitData = split("/", $directory);
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
     * @param byte[] $message
     * @return byte[]
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
     * @param byte[] $data
     * @param string $IP
     * @param int $i
     * @return byte[]
     */
    private function makeCell($data, $IP, $i)
    {
        $p = $IP . "::" . implode(array_map("chr", $data));
        print($i . "th peel: " . $p);
        $peel = unpack('C*', $p);
        $encPeel = $this->encrypt($peel, $i);
        print("encrypted peel in bytes--> " . $this->bytesToString($encPeel));
        print("len of encrypted peel in bytes--> " . count($encPeel));
        return $encPeel;
    }

    /**
     * @param byte[] $peel
     * @param int $i
     * @return byte[]
     */
    private function encrypt($peel, $i)
    {
        $e = new BigInteger($this->E[$i]);
        $n = new BigInteger($this->N[$i]);
        $enc = (new BigInteger($peel)) -> modPow($e, $n) -> toByteArray();
        return $enc;
    }

    /**
     * @param byte[]
     * @return string
     */
    private static function bytesToString($e)
    {
        //return implode(array_map("chr", $e));
        $test = "";
        foreach ($e as $b) {
            $test += chr($b);
        }
        return $test;
    }

    /**
     * @param byte[] $message_router1
     */
    private function torouter1($message_router1)
    {
        try {
            self::$clientlog->info("Data being sent through Proxy Routers.");
            $client = new Socket($this->IP[2], 9091);
            $this->dout = new DataOutputStream($client->getOutputStream());
            $this->dout->writeInt(count($message_router1));
            $this->dout->write($message_router1);
        } catch (\Exception $ex) {
            self::$clientlog->severe("Data couldn't be sent to the Router. Exiting Program");
        }
    }
}
