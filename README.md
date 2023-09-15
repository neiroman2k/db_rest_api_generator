Конфигурация настраивается в файле

    app/config.php 

Для запуска контейнера

    docker compose up --build -d

Если выскакивает ошибка "php could not find driver", 
то необходимо в файле etc/php.ini раскоментировать строку

    extension=pdo_mysql

Для пересборки сриптов выполнить из консоли

    docker exec -it rest_api_generator /app/rebuild_api

Скрипты будут созданы в каталогах

    В контейнере - /var/www/html
    В приложении - ./result
    http://localhost:8007/api.tgz


Сгенерированное API для тестов по умолчанию доступно на

    http://localhost:8007/api/....

Например

    http://localhost:8007/api/cards/read
