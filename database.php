<?php
class Database {
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    private string $port;
    private ?PDO $conn = null;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: 'localhost');
        $this->db_name = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'food_ordering_db');
        $this->username = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root');
        $this->password = getenv('DB_PASSWORD') ?: (getenv('MYSQLPASSWORD') ?: '');
        $this->port = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306');
    }

    public function getConnection(): PDO {
        if ($this->conn === null) {
            try {
                $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
                $this->conn = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } catch (PDOException $e) {
                throw new Exception("Database Connection Failure: " . $e->getMessage());
            }
        }
        return $this->conn;
    }
}