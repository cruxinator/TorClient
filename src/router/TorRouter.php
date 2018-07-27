<?php
namespace cruxinator\TorClient\router;
use cruxinator\TorClient\Lib\BigInteger;
use cruxinator\TorClient\Lib\Logger;
use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

class TorRouter
{
    /**
     * @var Logger
     */
    private static $routerlog;
    /**
     * @var \Workerman\Connection\ConnectionInterface
     */
    private $router;
    /**
     * @var String[]
     */
    private $E;
    /**
     * @var String[]
     */
    private $N;
    /**
     * @var String[]
     */
    private $D;
    /**
     * @var String
     */
    private $DirIP = "127.0.0.1";
    //BigInt
    /**
     * @var BigInteger
     */
    private $p;
    /**
     * @var BigInteger
     */
    private $q;
    /**
     * @var BigInteger
     */
    private $e;
    /**
     * @var BigInteger
     */
    private $d;
    /**
     * @var BigInteger
     */
    private $n;
    /**
     * @var BigInteger
     */
    private $phi;
    /**
     * @var int
     */
    private $RouterPort = 9091;
    /**
     * @var int
     */
    private $DirPort = 9090;

    function __construct()
    {
        self::$routerlog = Logger::getLogger("router");
        self::$routerlog->info("Tor Router Initialized.");
        $this->E = array(); // should always be 3
        $this->N = array(); // should always be 3
        $this->D = array(); // should always be 3
    }
    public static function main()
    {
        self::$routerlog->info("Tor Router running");
        $OR = new TorRouter();
        $OR->loginit();
        $OR->genKey();
        $OR->sendToDir();
        $OR->getData();
        if(!defined('GLOBAL_START'))
        {
            Worker::runAll();
        }
    }

    private function loginit()
    {
        try {
            $logFile = fopen(__DIR__ . "/TorRouter.log", 'w');
            self::$routerlog->addHandler($logFile);

        }
        catch (\Exception $ex) {
            self::$routerlog->severe("Exception raised in creating log file. Exiting program.");
        }
    }

    private function genKey()
    {
        self::$routerlog->info("RSA keys being generated...");
        $this->key(256, 0);
        $this->key(512, 1);
        $this->key(1024, 2);
        self::$routerlog->info("RSA keys generated.");
    }

    /**
     * @param int $bitlength
     * @param int $index
     */
    private function key($bitlength, $index)
    {
        $this->p   = BigInteger::probablePrime($bitlength);
        $this->q   = BigInteger::probablePrime($bitlength);
        $this->n   = $this->p->mul($this->q);
        $this->phi = $this->p->sub(BigInteger::ONE())->mul($this->q->sub(BigInteger::ONE()));
        $this->e   = BigInteger::probablePrime($bitlength / 2);

        while ($this->phi->gcd($this->e)->compareTo(BigInteger::ONE()) > 0 && $this->e->compareTo($this->phi) < 0) {
            $this->e->add(BigInteger::ONE());
        }

        $this->d         = $this->e->modInverse($this->phi);
        $this->E[$index] = $this->e->toString();
        $this->N[$index] = $this->n->toString();
        $this->D[$index] = $this->d->toString();
    }

    private function sendToDir()
    {
        try {
            $this->router = new AsyncTcpConnection('tcp://'.$this->DirIP.':'.$this->DirPort);
            /**
             * @param \Workerman\Connection\ConnectionInterface $remote_connection
             */
            $this->router->onConnect = function($remote_connection)
            {
                $remote_connection->send("0/" . $this->E[0] . "/" . $this->N[0] . "/" .
                    $this->E[1] . "/" . $this->N[1] . "/" . $this->E[2] . "/" . $this->N[2]);
            };
            $this->router->connect();

            for ($i = 0; $i < 3; $i++) {
                print("E[" . $i . "]=" . $this->E[$i]);
                print("N[" . $i . "]=" . $this->N[$i]);
            }
            $this->router->close();
        }
        catch (\Exception $ex) {
            self::$routerlog->severe("Unable to connect to Directory. Exiting program.");
            exit(0);
        }
    }

    /**
     * @return array|null|
     */
    private function getData()
    {
        self::$routerlog->info("Waiting to receive data.");
        try {
            //$RouterAsServer = new ServerSocket($this->RouterPort, 10);
            $RouterAsServer= new Worker('tcp://0.0.0.0:' . $this->RouterPort);
            /**
             * @param \Workerman\Connection\ConnectionInterface  $incoming
             */
            $RouterAsServer->onConnect= function($incoming)
            {
                self::$routerlog->info("Connection with client established. " . $incoming->getRemoteIp());
            };
            $outter = $this;
            /**
             * @param \Workerman\Connection\ConnectionInterface $connection
             * @param [] $buffer
             */
            $RouterAsServer->onMessage = function($connection, $buffer) {
                $receivedData = $buffer;
                self::$routerlog->info("Data Received.");
                print("Data received in Bytes" . $this->bytesToString($receivedData));
                print(count($receivedData));
                $receivedDataDecrpt  = $this->decrypt($receivedData, count($receivedData));
                print(self::bytesToString($receivedDataDecrpt));
                $this->sendData($receivedDataDecrpt);
                $connection->close();
            };

        }
        catch (\Exception $ex) {
            self::$routerlog->severe("Data receiving failed.");
        }
    }

    /**
     * @param int $len
     * @return array
     */
    private function decrypt($data, $len)
    {
        $key = 0;
        if ($len == 256 || $len == 257) {
            $key = 2;
        } else if ($len == 128 || $len == 129) {
            $key = 1;
        }
        print("len=" . $len);
        print("keys to be used " . $key);
        print("Data to decrypt in bytes: " . self::bytesToString($data));
        return (new BigInteger($data))->powMod(new BigInteger($this->D[$key]),new BigInteger($this->N[$key]))->toByteArray();

    }

    private function sendData($decryptedData)
    {
        print("decypted data in string: " . self::bytesToString($decryptedData));
        $st     = explode("::",self::bytesToString($decryptedData));
        $nextIp = $st[0];
        $m      = $st[0];
        $msg    = unpack('C*', trim($m));
        print("next peel: " . self::bytesToString($msg));
        $l = count($msg);
        try {
            $RouterAsClient = new AsyncTcpConnection('tcp://'.$nextIp.':'.$this->RouterPort);
            /**
             * @param \Workerman\Connection\ConnectionInterface $remote_connection
             */
            $RouterAsClient->onConnect = function($remote_connection) use($l,$msg,$m)
            {
                $remote_connection->send($l);
                $remote_connection->send($m);
                $remote_connection->close();
            };
            $RouterAsClient->connect();

        }
        catch (\Exception $ex) {
            self::$routerlog->severe("Attempt to establish connection with other router failed. Exiting progream.");
        }
    }
    private static function bytesToString($e)
    {
        //return implode(array_map("chr", $e));
        $test = "";
        foreach ($e as $b) {
            $test += chr($b);
        }
        return $test;
    }

}