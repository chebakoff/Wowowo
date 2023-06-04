<?php

header('Content-Type: text/html; charset=utf-8');

// Установка часового пояса на Московское время.
    date_default_timezone_set('Europe/Moscow');

$update = file_get_contents('php://input');

// Декодирование JSON-данных
$data = json_decode($update, true);

// Получение данных из Telegram (переменные)
    $chatId = $data['message']['chat']['id'];
    $username = $data['message']['chat']['username'];
    $firstName = $data['message']['chat']['first_name'];
    $lastName = $data['message']['chat']['last_name'];
    $messageText = $data['message']['text'];
    $messageDate = $data['message']['date'];
    
// Проверяем chatId, если пустой то останавливаем код
checkChatId($chatId);

// Функция проверки chatId на пустоту. 
function checkChatId($chatId) {
    if (empty($chatId)) {
        echo "chatId не определен. Скрипт будет остановлен.";
        exit; // Останавливаем выполнение скрипта
    }
}


// Регистрация нового пользователя.
function registerUser($db, $chatId, $username, $firstName, $lastName) {
    // Установка часового пояса на Московское время.
    date_default_timezone_set('Europe/Moscow');

    // Установка даты регистрации (текущая дата).
    $registerDate = date('Y-m-d');

    // Проверка наличия значений для firstName и lastName.
    $firstName = ($firstName != '') ? $firstName : 'N/A';
    $lastName = ($lastName != '') ? $lastName : 'N/A';

    // SQL-запрос для добавления нового пользователя в базу данных.
    $query = "INSERT INTO Users (chat_id, username, register_date) VALUES (:chatId, :username, :registerDate)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':chatId', $chatId);
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':registerDate', $registerDate);

    // Выполнение запроса.
    $success = $stmt->execute();

    if (!$success || $db->lastInsertId() <= 0) {
        $errorInfo = $stmt->errorInfo();
        $errorMessage = "Ошибка при выполнении SQL-запроса: " . $errorInfo[2];
        logError($errorMessage);
        return;
    }

    // Вызов скрипта add_peer.sh для создания конфигурации VPN.
    $command = "/var/scripts_vpn/add_peer.sh $chatId";
    exec($command, $output, $returnCode);

    // Проверка статуса выполнения команды.
    if ($returnCode === 0) {
        // Команда успешно выполнена.
        $configFilePath = "/etc/wireguard/clients/{$chatId}.conf";

        // Установка даты выдачи конфигурации (текущая дата) и даты окончания подписки (через 2 недели).
        $issueDate = date('Y-m-d');
        $subscriptionEndDate = date('Y-m-d', strtotime("+2 weeks"));
        
        // Имя первого конфигуратора
    	$firstConfigName = "Основной";

        // Сохранение пути к созданному файлу в базе данных.
        $query = "INSERT INTO VPN_Configurations (chat_id, config_name, config_data, issue_date, subscription_end_date, active) VALUES (:chatId, :configName, :configFilePath, :issueDate, :subscriptionEndDate, :active)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':chatId', $chatId);
        $stmt->bindParam(':configName', $firstConfigName);
        $stmt->bindParam(':configFilePath', $configFilePath);
        $stmt->bindParam(':issueDate', $issueDate);
        $stmt->bindParam(':subscriptionEndDate', $subscriptionEndDate);
        $stmt->bindValue(':active', true, PDO::PARAM_BOOL);

        $success = $stmt->execute();

        if (!$success || $db->lastInsertId() <= 0) {
            $errorInfo = $stmt->errorInfo();
            $errorMessage = "Ошибка при выполнении SQL-запроса: " . $errorInfo[2];
            logError($errorMessage);
            return;
        }

        echo "Пользователь успешно зарегистрирован и создана конфигурация VPN.";
    } else {
        // Ошибка выполнения команды.
        $errorMessage = "Ошибка создания конфигурации VPN. " . "(" . $chatId . ")";
        logError($errorMessage);
    }
}


// Функция проверки telegram webhook
function checkTelegramWebhook($botToken, $webhookUrl) {
    // API URL для проверки установки webhook.
    $apiUrl = "https://api.telegram.org/bot$botToken/getWebhookInfo";

    // Отправка запроса для проверки установки webhook.
    $response = file_get_contents($apiUrl);

    if ($response === false) {
        $errorMessage = "Ошибка при отправке запроса для проверки webhook";
        logError($errorMessage);
        return;
    }

    // Парсинг ответа.
    $data = json_decode($response, true);

    // Проверка статуса установки webhook.
    if ($data['ok']) {
        // Webhook уже установлен.
        echo "Telegram webhook уже установлен.\n";

        // Проверка, содержит ли URL установленного Webhook ваш URL.
        if ($data['result']['url'] === $webhookUrl) {
            echo "Webhook URL соответствует вашему серверу.\n";
        } else {
            echo "Webhook URL не соответствует вашему серверу.\n";
        }
    } else {
        // Webhook не установлен.
        echo "Telegram webhook не установлен...\n";
    }
}

function connectToDatabase($servername, $database, $username_db, $password) {
    try {
        // Создание нового объекта PDO и подключение к базе данных.
        $db = new PDO("mysql:host=$servername;dbname=$database;charset=utf8", $username_db, $password);
        return $db;
    } catch(PDOException $e) {
        // Обработка ошибок при подключении.
        $errorMessage = "Connection failed: " . $e->getMessage();
        logError($errorMessage);
        return false;
    }
}

// Проверка пользователя в базе данных.
function checkUser($db, $chatId) {
    // SQL-запрос для поиска пользователя по chat_id.
    $query = "SELECT COUNT(*) FROM Users WHERE chat_id = :chatId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':chatId', $chatId);

    // Выполнение запроса.
    $success = $stmt->execute();

    // Если запрос не удался, записываем ошибку в лог и возвращаем false.
    if (!$success) {
        $errorInfo = $stmt->errorInfo();
        $errorMessage = "Ошибка при выполнении SQL-запроса: " . $errorInfo[2];
        logError($errorMessage);
        return false;
    }

    // Получение результата запроса.
    $userCount = $stmt->fetchColumn();

    // Если пользователь найден (COUNT(*) вернет число больше 0), то возвращаем true. 
    return $userCount > 0;
}

// Функция отправки фотографии
function sendPhoto($chatId, $photoPath, $botToken, $caption = '') {
    // API URL для отправки фотографии.
    $apiUrl = "https://api.telegram.org/bot$botToken/sendPhoto";

    // Создание POST-запроса с помощью CURL.
    $postData = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($photoPath),
        'caption' => $caption
    ];

    // Инициализация CURL-сеанса.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    // Отправка запроса.
    $result = curl_exec($ch);

    if ($result === false) {
        $errorMessage = "Ошибка отправки фотографии к: " . $chatId;
        logError($errorMessage);
    }

    // Закрытие CURL-сеанса.
    curl_close($ch);
}


// Функция отправки стикера со своего сервера или telegram

// Отправка стикера по пути к файлу
//sendSticker($chatId, $stickerPath, $botToken);

// Отправка стикера по идентификатору
//sendSticker($chatId, $stickerId, $botToken);

function sendSticker($chatId, $sticker, $botToken) {
    // API URL для отправки стикера.
    $apiUrl = "https://api.telegram.org/bot$botToken/sendSticker";

    // Параметры запроса.
    $postData = [
        'chat_id' => $chatId
    ];

    // Проверяем, является ли $sticker путем к файлу или идентификатором стикера.
    if (is_file($sticker)) {
        $postData['sticker'] = new CURLFile($sticker);
    } else {
        $postData['sticker'] = $sticker;
    }

    // Инициализация CURL-сеанса.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    // Отправка запроса.
    $result = curl_exec($ch);

    if ($result === false) {
        $errorMessage = "Ошибка отправки стикера к: " . $chatId;
        logError($errorMessage);
    }

    // Закрытие CURL-сеанса.
    curl_close($ch);
}


// Функция отправки документа
function sendDocument($chatId, $documentPath, $botToken, $caption = '') {
    // API URL для отправки документа.
    $apiUrl = "https://api.telegram.org/bot$botToken/sendDocument";

    // Создание POST-запроса с помощью CURL.
    $postData = [
        'chat_id' => $chatId,
        'document' => new CURLFile($documentPath),
        'caption' => $caption
    ];

    // Инициализация CURL-сеанса.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    // Отправка запроса.
    $result = curl_exec($ch);

    if ($result === false) {
        $errorMessage = "Ошибка отправки документа к: " . $chatId;
        logError($errorMessage);
    }

    // Закрытие CURL-сеанса.
    curl_close($ch);
}



// Функция удаления администратора из базы (временная функция для удобной отладки кода)
function DeleteAdmin($db, $chatId) {
    try {
        // Удаление файла wg0.conf
        $wg0ConfFile = '/etc/wireguard/wg0.conf';
        if (file_exists($wg0ConfFile)) {
            unlink($wg0ConfFile);
            sendMessage($chatId, "Файл $wg0ConfFile успешно удален.", $botToken);
        } else {
            sendMessage($chatId, "Файл $wg0ConfFile не существует.", $botToken);
        }

        // Удаление директории /etc/wireguard/clients
        $clientsDir = '/etc/wireguard/clients';
        if (is_dir($clientsDir)) {
            // Рекурсивное удаление файлов внутри директории
            $files = glob($clientsDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            // Удаление самой директории
            rmdir($clientsDir);
            sendMessage($chatId, "Директория $clientsDir успешно удалена.", $botToken);
        } else {
            sendMessage($chatId, "Директория $clientsDir не существует.", $botToken);
        }

        // Удаление записей из таблицы VPN_Configurations
        $query1 = "DELETE FROM VPN_Configurations WHERE chat_id = :chatId";
        $stmt1 = $db->prepare($query1);
        $stmt1->bindParam(':chatId', $chatId);
        $stmt1->execute();

        // Удаление записей из таблицы Users
        $query2 = "DELETE FROM Users WHERE chat_id = :chatId";
        $stmt2 = $db->prepare($query2);
        $stmt2->bindParam(':chatId', $chatId);
        $stmt2->execute();

        // Отправка сообщения об успешном удалении
        sendMessage($chatId, "Записи успешно удалены.", $botToken);
        return true; // Возвращаем true после успешного удаления
    } catch (PDOException $e) {
        $errorMessage = "Ошибка при выполнении SQL-запроса: " . $e->getMessage();
        logError($errorMessage);
        return false;
    }
}





// Токен вашего бота Telegram.
$botToken = "6255140607:AAGUG50Ac7CyZREIfzq-0uCQszSR7t_tm-Y";

// URL вашего сервера, где расположен этот файл.
$webhookUrl = "https://chebak.pro/EpicVPNergy/index.php";

// Проверка установки webhook Telegram.
checkTelegramWebhook($botToken, $webhookUrl);

// Параметры подключения к базе данных.
$servername = "localhost";
$database = "bd";
$username_db = "root";
$password = "Mysqli_SQWIRT(55TAILS)";

// Проверка, есть ли данные в запросе Telegram
if (isset($data)) {

    // Подключение к базе данных.
    $db = connectToDatabase($servername, $database, $username_db, $password);

    if ($db) {
        // Подключение к базе данных успешно выполнено. Можно выполнять операции с базой данных.
        echo "MySQL connect!\n";
        
        if ($messageText === "del" && $chatId == '1034612852') {
    		DeleteAdmin($db, $chatId);
    		if (!checkUser($db, $chatId)) {
        		sendMessage($chatId, "Админ удален", $botToken);
        		return; // Прерываем выполнение функции после отправки сообщения
    		}
		}


        if (checkUser($db, $chatId)) {
            // Пользователь зарегистрирован.
            // Отправляем сообщение о регистрации.
            
            $stickerId = "CAACAgIAAxkBAAEJNJ5ke-SqZkwU3dkZifAN_d571tsjygACfgADUomRI0PeyNVZaNSCLwQ";
            sendSticker($chatId, $stickerId, $botToken);
            
            sendMessage($chatId, "Привет, " . $firstName . "\nВы уже зарегистрированы!\nВаш установочный файл:", $botToken);
            
            $documentPath = "/etc/wireguard/clients/" . $chatId . ".conf";
            sendDocument($chatId, $documentPath, $botToken);
            
            $stickerId = "CAACAgIAAxkBAAEJNKVke-Wzu7yvgG9wJkVInJDww3v44AACgQADUomRI3VTQUWj5J3DLwQ";
            sendSticker($chatId, $stickerId, $botToken);

            return true;
        } else {
            // Пользователь НЕ зарегистрирован.
            // Отправляем приветствие, инструкцию по утановке WG, регистрируем.
            sendMessage($chatId, "Привет, " . $firstName . "!\nЯ Ваш бот VPNergy! Я помогу Вам управлять Вашим VPN-подключением.", $botToken);
            // Стикер
            $stickerId = "CAACAgIAAxkBAAEJNJtke92uDOMr-XySPTv59U8H2C5qjgACWxkAApITQEg3UQr5oSE8ny8E";
            sendSticker($chatId, $stickerId, $botToken);
            //Вызываю функцию РЕГИСТРАЦИИ ПОЛЬЗОВАТЕЛЯ
            registerUser($db, $chatId, $username, $firstName, $lastName);
            
            $keyboard = json_encode([
    			'inline_keyboard' => [
        		[
            		['text' => 'iOS', 'url' => 'https://itunes.apple.com/us/app/wireguard/id1441195209?ls=1&mt=8'],
            		['text' => 'Android', 'url' => 'https://play.google.com/store/apps/details?id=com.wireguard.android']
        			],
        		[
            		['text' => 'MacOS', 'url' => 'https://itunes.apple.com/us/app/wireguard/id1451685025?ls=1&mt=12'],
            		['text' => 'Windows', 'url' => 'https://download.wireguard.com/windows-client/wireguard-installer.exe']
        ]
    ]
]);

			sendMessage($chatId, "Выберите приложение WireGuard для загрузки", $botToken, $keyboard);

            
        }
    } else {
        // Подключение к базе данных не удалось.
        // Дополнительная обработка ошибки или вывод сообщения об ошибке.
        echo "ОШИБКА ПОДКЛЮЧЕНИЯ К БАЗЕ!\n";
        logError("Ошибка подключения к базе данных");
    }
}

// Отправка сообщения через API Telegram.
function sendMessage($chatId, $message, $botToken, $keyboard = null) {
    // Проверка наличия значения chatId
    if (empty($chatId)) {
        $errorMessage = "Не указан chat_id для отправки сообщения";
        logError($errorMessage);
        exit;
    }

    // API URL для отправки сообщений.
    $apiUrl = "https://api.telegram.org/bot$botToken/sendMessage";

    // Подготовка данных для отправки
    $data = [
        'chat_id' => $chatId,
        'text' => $message
    ];

    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }

    // Используем cURL вместо file_get_contents для большей гибкости
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    // Отправка сообщения.
    $result = curl_exec($ch);

    if ($result === false) {
        $errorMessage = "Ошибка отправки сообщения к: " . $chatId;
        logError($errorMessage);
    }

    curl_close($ch);
}



// Запись ошибки в лог-файл.
function logError($errorMessage) {
    $logFilePath = '/var/www/html/EpicVPNergy/vpn.log';
    $currentTime = date('Y-m-d H:i:s');
    $errorLog = $currentTime . ' - ' . $errorMessage . "\n";
    file_put_contents($logFilePath, $errorLog, FILE_APPEND);
}

?>
