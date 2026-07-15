<?php
// .env 
require_once "config.php";

// singleton 
class Database {
    private $username;
    private $password;
    private $host;
    private $database;
    private static ?PDO $connection = null;

    public function __construct()
    {
        $this->username = USERNAME;
        $this->password = PASSWORD;
        $this->host = HOST;
        $this->database = DATABASE;
    }

    public function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        try {
            self::$connection = new PDO(
                "pgsql:host=$this->host;port=5432;dbname=$this->database;sslmode=prefer",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            self::$connection->exec("SET client_encoding TO 'UTF8'");

            return self::$connection;
        }
        catch(PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new RuntimeException("Database connection failed.", 500, $e);
        }
    }

    public function disconnect() {
        self::$connection = null;
    }
}
