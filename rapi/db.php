<?php


class Database {
    private $host = "localhost";
    private $user = "u825845895_fbn4615123";
    private $pass = "Os@4615123";
    private $dbName = "u825845895_fbndatabase";
    public $conn;

    public function getConnection(){
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->dbName,
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_PERSISTENT => true,   // Enables persistent connection (connection pooling)
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            );
        } catch (\Throwable $th) {
            echo "Connection error: " . $th->getMessage();
        }

        return $this->conn;
    }
}

?>
