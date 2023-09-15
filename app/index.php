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
                    case "read": {
                        $result = $this->read();
                        break;                    
                    }                
                    case "insert": {
                        break;                    
                    }                
                    case "update": {
                        $result = $this->update();
                        break;                    
                    }                
                    case "delete": {
                        break;                    
                    }
                    default: {
                        http_response_code(404);
                        $result = ["code" => 404, "message" => "Invalid action"];
                    }                
                }
                
                return $result;                
            }
            
            public function read() {
                $id = $_REQUEST["id"];
                
                $query = "select ' . join(',', $query_fields) . ' from ' . $table_name . '";
                if ( $id ) {
                    $query .= " where id=:id";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(":id", $id); 
                } else {
                    $stmt = $this->conn->prepare($query);
                }
                
                // выполняем запрос
                $stmt->execute();
                
                // получаем извлеченную строку
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if ( !$data ) {
                    // код ответа - 404 Не найдено
                    http_response_code(404);
                
                    // сообщим пользователю, что такой товар не существует
                    //echo json_encode(, JSON_UNESCAPED_UNICODE);
                    return ["code" => 404, "message" => "Товар не существует"];
                }
                
                return [
                    "code" => 200,
                    "items" => $data
                ];
            }

            /**
              $new_data = [
                  "field_name" => "new data",                
              ]              
            */ 
            public function update() {
                $id = $_REQUEST["id"];
                $new_data = $_POST;
                unset($new_data["id"]);
                
                if ( $new_data = [] ) {
                    return false;
                }

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
                      
                $query = "update $this->table_name set $fields_str where id=:id";
                $stmt = $this->conn->prepare($query);
                
                foreach ($new_data as $field_name => $new_value ) {
                    $stmt->bindParam(":$field_name", $new_value);
                };
                $stmt->bindParam(":id", $id);

                print("$query\n");
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
fputs($fp,'
<?php
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