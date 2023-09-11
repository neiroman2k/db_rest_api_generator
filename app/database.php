<?php

class Database
{
    // укажите свои учетные данные базы данных
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    // получаем соединение с БД
    public function getConnection($host,$db_name,$username,$password)
    {
        $this->conn = null;

        try {
            $this->host = $host;
            $this->db_name = $db_name;
            $this->username = $username;
            $this->password = $password;

            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
        } catch (PDOException $exception) {
            echo "Ошибка подключения: " . $exception->getMessage();
        }

        return $this->conn;
    }

    public function list_tables()
    {
        if ( $this->conn == null ) return false;

        $sql = 'SHOW TABLES';
        $query = $this->conn->query($sql);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function list_fields($table_name) {
        if ( $this->conn == null ) return false;

        $sql = "show fields from $table_name";
        $query = $this->conn->query($sql);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

}