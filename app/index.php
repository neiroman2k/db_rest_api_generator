<?php
$pwd = GetEnv('PWD');
if ( $pwd != '/app' ) {
    print("pwd=$pwd\n");
    die('Can only be executed from docker container');
}

global $config;
include_once 'config.php';

// подключение базы данных и файл, содержащий объекты
include_once "database.php";

// получаем соединение с базой данных
$database = new Database();
$db = $database->getConnection(
    $config['db_host'],
    $config['db_name'],
    $config['db_username'],
    $config['db_password']
);

function parseTable($database, $table_name, $dst_file) {
    $fields = $database->list_fields($table_name);
    //print_r($fields);

    $f = [];
    $fields_arr = [];
    $query_fields = [];
    foreach ($fields as $field) {
        $field_name = $field['Field'];
        $field_type = $field['Type'];
        $f[] = "            public \$$field_name; // $field_type";

        $fields_arr[] = "\"$field_name\""; // для списка полей
        $query_fields[] = "`a`.`$field_name`";
    }

    $class_name = strtoupper($table_name);
    $php_class = '<?php
        class '.$class_name.' 
        {
            // подключение к базе данных и таблице "'.$table_name.'"
            private $conn;
            private $table_name = "'.$table_name.'";
            
            // список полей
            private $table_fields = [ '. join(", ", $fields_arr) . '];
            
            // конструктор для соединения с базой данных
            public function __construct($db)
            {
                $this->conn = $db;
            }
            
            public function action($action) {
                switch ($action) {
                    case "get": {
                        $result = $this->get($_REQUEST["id"]);
                        break;                    
                    }                
                    case "read": {
                        $result = $this->read();
                        break;                    
                    }                
                    case "create": {
                        $result = $this->create();
                        break;                    
                    }                
                    case "update": {
                        $result = $this->update();
                        break;                    
                    }                
                    case "delete": {
                        $result = $this->delete();
                        break;                    
                    }
                    default: {
                        http_response_code(404);
                        $result = ["code" => 404, "message" => "Invalid action"];
                    }                
                }
                
                return $result;                
            }
            
            /** *****************
                Get single item            
            ********************/
            public function get($id) {
                if ( !$id ) {
                    http_response_code(501);
                
                    return ["code" => 501, "message" => "[id] parameter is not defined"];                
                }
                
                $query = "select ' . join(',', $query_fields) . ' from ' . $table_name . ' a where a.id=:id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(":id", $id); 
                
                // выполняем запрос
                $stmt->execute();
                
                // получаем извлеченную строку
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if ( !$data ) {
                    // код ответа - 404 Не найдено
                    http_response_code(404);
                
                    return ["code" => 404, "message" => "Item does not exist"];
                }
                
                return [
                    "code" => 200,
                    "item" => $data
                ];
            }
            /** *****************
                Read data            
            ********************/
            public function read() {
                $query = "select ' . join(',', $query_fields) . ' from ' . $table_name . ' a";
                $stmt = $this->conn->prepare($query);
                
                // выполняем запрос
                $stmt->execute();
                
                // получаем извлеченную строку
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);                
               
                return [
                    "code" => 200,
                    "items" => $data
                ];
            }
            
            /** *****************
                Create data            
            ********************/
            public function create() {               
                $new_data = $_REQUEST;
                unset($new_data["id"]);
                
                if ( count($new_data) == 0 ) {
                      http_response_code(501);
                      return [
                        "code" => 501,
                        "items" => "No data for create"
                      ];                                   
                      return;
                }
                
                //print("new_data =>");print_r($new_data);print("<br>");
                //phpinfo();die();
                
                $fields_arr = [];
                $bind_values_arr = [];
                foreach ($new_data as $field_name => $new_value ) {
                    if ( !in_array($field_name, $this->table_fields) ) {
                      http_response_code(501);
                      return [
                        "code" => 501,
                        "items" => "Field [$field_name] not exists in table [$this->table_name]"
                      ];                                   
                      return;
                    }
                    
                    $fields_arr[] = "`$field_name`";
                    $bind_values_arr[] = ":$field_name";
                };
                $fields_str = join(",",$fields_arr);
                $bind_values_str = join(",",$bind_values_arr);          
                      
                $query = "insert into $this->table_name ($fields_str) values ($bind_values_str)";
                $stmt = $this->conn->prepare($query);
                
                foreach ($new_data as $field_name => $new_value ) {
                    $stmt->bindValue(":$field_name", $new_value);
                };
                //print("$query<br>");
                
                // выполняем запрос
                try {
                    $this->conn->beginTransaction();
                    $stmt->execute();
                    $new_id = $this->conn->lastInsertId();               
                    $this->conn->commit();
                    
                    return [
                        "code" => 200,
                        "msg" => "Create complete",
                        "id" => $new_id
                    ];
                } catch(PDOException $e) {
                    $this->conn->rollback();
                    
                    http_response_code(501);
                      
                    return [
                        "code" => 501,
                        "msg" => "Internal exception on create",
                        "details" => $e->getMessage()
                    ];
                }
            }

            /**
              Update data
              
              $new_data = [
                  "field_name" => "new data",                
              ]              
            */ 
            public function update() {
                if ( !($id = $_REQUEST["id"])) {
                      http_response_code(501);
                      return [
                        "code" => 501,
                        "items" => "No [id] field in request"
                      ];                                   
                };

                $found = $this->get($id);
                if ( $found["code"] != 200 ) {
                    return $found;
                }
                
                $new_data = $_REQUEST;
                unset($new_data["id"]);
                
                if ( count($new_data) == 0 ) {
                      http_response_code(501);
                      return [
                        "code" => 501,
                        "items" => "No data for update"
                      ];                                   
                      return;
                }
                
                //print("new_data =>");print_r($new_data);print("<br>");
                
                $fields_arr = [];
                foreach ($new_data as $field_name => $new_value ) {
                    if ( !in_array($field_name, $this->table_fields) ) {
                      http_response_code(501);
                      return [
                        "code" => 501,
                        "items" => "Field [$field_name] not exists in table [$this->table_name]"
                      ];                                   
                      return;
                    }
                    
                    $fields_arr[] = "`a`.`$field_name`=:$field_name";
                };
                $fields_str = join(",",$fields_arr);          
                      
                $query = "update $this->table_name `a` set $fields_str where a.`id`=:id";
                $stmt = $this->conn->prepare($query);
                //print("$query<br>");
                
                foreach ($new_data as $field_name => $new_value ) {
                    $stmt->bindValue(":$field_name", $new_value);
                    //print("bindValue :$field_name => $new_value<br>");
                };
                $stmt->bindValue(":id", $id);
                
                // выполняем запрос
                try {
                    $this->conn->beginTransaction();
                    $stmt->execute();
                    $this->conn->commit();
                    
                    return [
                        "code" => 200,
                        "msg" => "Update complete"
                    ];
                } catch(PDOException $e) {
                    $this->conn->rollback();
                    
                    http_response_code(501);
                    
                    return [
                        "code" => 501,
                        "msg" => "Internal exception on update",
                        "details" => $e->getMessage()
                    ];
                }
            }
            
            /**
              Delete data
              
            */ 
            public function delete() {
                if ( ! ($id = $_REQUEST["id"])) {
                      http_response_code(501);
                      return [
                        "code" => 501,
                        "items" => "No [id] field in request"
                      ];                                   
                };
                
                $found = $this->get($id);
                if ( $found["code"] != 200 ) {
                    return $found;
                }
                
                $query = "delete from $this->table_name where id=:id";
                $stmt = $this->conn->prepare($query);
                //print("$query<br>");
                
                $stmt->bindValue(":id", $id);
                
                // выполняем запрос
                try {
                    $this->conn->beginTransaction();
                    $stmt->execute();
                    $this->conn->commit();
                    
                    return [
                        "code" => 200,
                        "msg" => "Delete complete"
                    ];
                } catch(PDOException $e) {
                    $this->conn->rollback();
                    
                    http_response_code(501);
                    
                    return [
                        "code" => 501,
                        "msg" => "Internal exception on delete",
                        "details" => $e->getMessage()
                    ];
                }
                                
            }
        }        
    ?>';

    $fp = fopen($dst_file,'w+');
    fputs($fp, $php_class);
    fclose($fp);

    return $class_name;
}

$dst_dir = '/var/www/html';
$routes_dir = $dst_dir."/routes";
@mkdir($routes_dir, 0755,true);
$tables = $database->list_tables();
//print_r($tables);

$routes_switch = [];
$includes = "";

foreach ($tables as $table_name) {
    print("Parse table -> $table_name\n");
    if ( !in_array($table_name, $config['ignore_tables']) ) {
        $route_path = $routes_dir."/$table_name.php";
        if ( $result_route = parseTable($database,$table_name, $route_path) ) {
            $class_name = $result_route;
            $routes_switch[] = "\t\t\t\t".'case "'.$table_name.'": 
                {
                    $route = new '.$class_name.'($db);
                    $result = $route->action($action);                    
                    break;
                }                            
            ';
            $includes .= "include_once 'routes/$table_name.php';\n";
        }
    } else {
        print("Skip by configuration\n");
    }

    print("-----------------------------\n");
}
# --------
//print_r($routes_switch);die(0);

# Generate .htaccess
$fp = fopen($dst_dir."/.htaccess",'w+');
fputs($fp,'# ---
RewriteEngine on

RewriteBase /

RewriteRule .htaccess - [F]

RewriteCond %{REQUEST_FILENAME} !-f [OR]
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule '.substr($config['http_root'],1).'(.*)$ /'.$config['api_entrypoint_file'].' [QSA,NC,L]
');
fclose($fp);

# Copy database class
copy('database.php',$dst_dir."/database.php");

# Generate entrypoint for api
$fp = fopen($dst_dir."/".$config['api_entrypoint_file'],'w+');
fputs($fp,'<?php

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$config = [
  "db_host" => "'.$config['db_host'].'",
  "db_name" => "'.$config['db_name'].'",
  "db_username" => "'.$config['db_username'].'",
  "db_password" => "'.$config['db_password'].'",
];
include_once "database.php";
'.$includes.'

// создание подключения к базе данных
$database = new Database();
$db = $database->getConnection(
    $config["db_host"],
    $config["db_name"],
    $config["db_username"],
    $config["db_password"]
);

$http_root = "'.$config['http_root'].'";
$uri = $_SERVER["REQUEST_URI"];
// For test
// $uri = "/api/v2/cards/read/?id=1";
if ( $uri != "" ) {
  $len = strlen($http_root);
  $uri = substr($uri,$len);
}
//print("uri=$uri\n");
$url_arr=parse_url($uri);
//print_r($url_arr); 
$path = $url_arr["path"];
//$query = $url_arr["query"];

list($route,$action) = explode("/",  $path);

$result = null;
switch ( $route ) {
'.join("\n",$routes_switch).'
    default: {
        http_response_code(404);
        $result = ["code" => 404, "message" => "Invalid route"];    
    }
}

print(json_encode($result));

?>
');
fclose($fp);

?>