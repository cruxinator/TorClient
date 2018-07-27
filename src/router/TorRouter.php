<?php
namespace cruxinator\TorClient\router;
use cruxinator\TorClient\Lib\BigInteger;
use cruxinator\TorClient\Lib\Logger;

class TorRouter
{
    /**
     * @var Logger
     */
    private static $routerlog;
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
     * @var byte[]
     */
    private $data;
    /**
     * @var byte[]
     */
    private $decryptedData;
    /**
     * @var String
     */
    private $DirIP = "192.168.0.111";
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
        while (true) {
            $OR->data = $OR->getData();
            print(count($OR->data));
            $OR->decryptedData = $OR->decrypt(count($OR->data));
            print(self::bytesToString($OR->decryptedData));
            $OR->sendData();

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

        while ($this->phi->gcd(e)->compareTo(BigInteger::ONE()) > 0 && $this->e->compareTo($this->phi) < 0) {
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
            $this->router = new Socket($this->DirIP, $this->DirPort);
            $this->dout   = new DataOutputStream($this->router->getOutputStream());
            $this->dout->writeUTF("0/" . $this->E[0] . "/" . $this->N[0] . "/" .
                $this->E[1] . "/" . $this->N[1] . "/" . $this->E[2] . "/" . $this->N[2]);

            for ($i = 0; $i < 3; $i++) {
                print("E[" . $i . "]=" . $this->E[$i]);
                print("N[" . $i . "]=" . $this->N[$i]);
            }
            $this->dout->flush();
            $this->dout->close();
            $this->router->close();
        }
        catch (\Exception $ex) {
            self::$routerlog->severe("Unable to connect to Directory. Exiting program.");
            exit(0);
        }
    }

    /**
     * @return array|null|byte[]
     */
    private function getData()
    {
        self::$routerlog->info("Waiting to receive data.");
        try {
            $RouterAsServer = new ServerSocket($this->RouterPort, 10);
            $DataSender     = $RouterAsServer->accept();
            self::$routerlog->info("Connection with client established.");
            $this->din    = new DataInputStream($DataSender->getInputStream());
            $len          = $this->din->readInt();
            $receivedData = array(); // byte array of $len
            $this->din->readFully($receivedData);
            $this->din->close();
            $DataSender->close();
            $RouterAsServer->close();
            self::$routerlog->info("Data Received.");
            print("Data received in Bytes" . $this->bytesToString($receivedData));
            return $receivedData;
        }
        catch (\Exception $ex) {
            self::$routerlog->severe("Data receiving failed.");
        }
        return null;
    }

    /**
     * @param int $len
     * @return array|byte[]
     */
    private function decrypt($len)
    {
        $key = 0;
        if ($len == 256 || $len == 257) {
            $key = 2;
        } else if ($len == 128 || $len == 129) {
            $key = 1;
        }
        print("len=" . $len);
        print("keys to be used " . $key);
        print("Data to decrypt in bytes: " . self::bytesToString($this->data));
        return (new BigInteger($this->data))->modPow(new BigInteger($this->D[$key]),new BigInteger($this->N[$key]))->toByteArray();

    }

    private function sendData()
    {
        print("decypted data in string: " . self::bytesToString($this->decryptedData));
        $st     = new StringTokenizer(self::bytesToString($this->decryptedData), "::");
        $nextIp = $st->nextToken();
        $m      = $st->nextToken();
        $msg    = unpack('C*', trim($m));
        print("next peel: " . self::bytesToString($msg));
        $l = count($msg);
        try {

            print("so you want me to send to : " . $nextIp);
            print("the data i am sending is : " . self::bytesToString($msg));
            $RouterAsClient = new Socket($nextIp, $this->RouterPort);
            $this->dout     = new DataOutputStream($RouterAsClient->getOutputStream());
            $this->dout->writeInt(l);
            $this->dout->write(msg);
            $this->dout->flush();
            $RouterAsClient->close();
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