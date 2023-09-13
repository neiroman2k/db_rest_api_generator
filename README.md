Для запуска контейнера

    docker compose up --build

Если выскакивает ошибка "php could not find driver", 
то необходимо в файле C:\php\php.ini раскоментировать строку

    extension=pdo_mysql

Для пересборки сриптов выполнить из консоли

    docker exec -it rest_api_generator /app/rebuild_api

Скрипты будут созданы в каталогах

    В контейнере - /var/www/html
    В приложении - ./result
    http://localhost:8008/api.tgz


API по умолчанию доступно на

    http://localhost:8008/api/v2/....

Например

    http://localhost:8008/api/v2/cards/read