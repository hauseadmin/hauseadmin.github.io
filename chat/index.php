<?php
/*
.......................................................
.                     ProtoChat                      .
.                Updated for Mobile                  .
.                                                     .
.                  (C) By Protocoder                  .
.                     protocoder.ru                   .
.                                                     .
. ���������������� �� �������� Creative Commons BY-NC .
.   http://creativecommons.org/licenses/by-nc/3.0/    .
.......................................................
*/

session_start();

// Устанавливаем кодировку UTF-8 для всего скрипта
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

setlocale( LC_ALL, 'ru_RU.UTF-8', 'rus_RUS.UTF-8', 'Russian_Russia.UTF-8' );
setlocale( LC_NUMERIC, 'C' );

// Убираем magic quotes
if ( function_exists( "get_magic_quotes_gpc" ) && get_magic_quotes_gpc() === 1 ) {
    $_COOKIE = array_map( "stripslashes", $_COOKIE );
    $_POST = array_map( "stripslashes", $_POST );
}

define( "DBFILE", dirname( __FILE__ ) . "/chat.db" );
define( "ONLINEFILE", dirname( __FILE__ ) . "/online.json" );
define( "PWFILE", dirname( __FILE__ ) . "/pwd.txt" );
define( "ADMINFILE", dirname( __FILE__ ) . "/admin.txt" );
define( "REFRESHTIME", 5 * 1000 );
define( "HEADER", "ProtoChat" );
define( "COOKIEPATH", "/" );
define( "CHATTRIM", 0 );
define( "MAXUSERNAMELEN", 64 );
define( "MAXUSERTEXTLEN", 1024 );
define( "MEDIA_DIR", dirname( __FILE__ ) . "/media" );
define( "USERS_DIR", dirname( __FILE__ ) . "/users" );

// Создаем необходимые директории, если их нет
if (!file_exists(MEDIA_DIR)) {
    mkdir(MEDIA_DIR, 0755, true);
}
if (!file_exists(USERS_DIR)) {
    mkdir(USERS_DIR, 0755, true);
}

// Инициализируем переменные
$authenticated = false;
$authError = '';
$current_user = '';
$is_admin = false;

// Функция для проверки прав администратора
function isAdmin($username) {
    if (!file_exists(ADMINFILE)) {
        return false;
    }
    
    $adminUsername = trim(file_get_contents(ADMINFILE));
    return $username === $adminUsername;
}

// Функция для загрузки списка онлайн-пользователей
function loadOnlineUsers() {
    if (!file_exists(ONLINEFILE)) {
        return [];
    }
    
    $content = file_get_contents(ONLINEFILE);
    if (empty($content)) {
        return [];
    }
    
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// Функция для сохранения списка онлайн-пользователей
function saveOnlineUsers($onlineUsers) {
    // Удаляем неактивных пользователей (более 30 секунд нет активности)
    $currentTime = time();
    foreach ($onlineUsers as $user => $lastActivity) {
        if ($currentTime - $lastActivity > 30) {
            unset($onlineUsers[$user]);
        }
    }
    
    file_put_contents(ONLINEFILE, json_encode($onlineUsers));
}

// Функция для обновления времени активности пользователя
function updateUserActivity($username) {
    $onlineUsers = loadOnlineUsers();
    $onlineUsers[$username] = time();
    saveOnlineUsers($onlineUsers);
    return $onlineUsers;
}

// Authentication functions
function authenticateUser($login, $password) {
    if (!file_exists(PWFILE)) return false;
    
    $lines = file(PWFILE);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, ':') !== false) {
            list($user, $pass) = explode(':', $line, 2);
            if ($user == $login && password_verify($password, $pass)) {
                return true;
            }
        }
    }
    return false;
}

function userExists($login) {
    if (!file_exists(PWFILE)) return false;
    
    $lines = file(PWFILE);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, ':') !== false) {
            list($user, $pass) = explode(':', $line, 2);
            if ($user == $login) {
                return true;
            }
        }
    }
    return false;
}

function registerUser($login, $password) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    file_put_contents(PWFILE, "$login:$hashed\n", FILE_APPEND);
    
    // Создаем папку пользователя
    $userDir = USERS_DIR . '/' . $login;
    if (!file_exists($userDir)) {
        mkdir($userDir, 0755, true);
    }
    
    return true;
}

// Handle authentication
if (isset($_POST['auth_action'])) {
    $auth_action = $_POST['auth_action'];
    if ($auth_action == 'login') {
        $login = isset($_POST['login']) ? $_POST['login'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        
        if (authenticateUser($login, $password)) {
            $_SESSION['user'] = $login;
            $_SESSION['authenticated'] = true;
            
            // Проверяем права администратора
            $_SESSION['is_admin'] = isAdmin($login);
            
            // Обновляем активность пользователя
            updateUserActivity($login);
        } else {
            $authError = 'Неверные учетные данные';
        }
    } elseif ($auth_action == 'register') {
        $login = isset($_POST['login']) ? $_POST['login'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm = isset($_POST['confirm']) ? $_POST['confirm'] : '';
        
        if ($password !== $confirm) {
            $authError = 'Пароли не совпадают';
        } elseif (userExists($login)) {
            $authError = 'Пользователь уже существует';
        } else {
            if (registerUser($login, $password)) {
                $_SESSION['user'] = $login;
                $_SESSION['authenticated'] = true;
                $_SESSION['is_admin'] = isAdmin($login);
                
                // Обновляем активность пользователя
                updateUserActivity($login);
            } else {
                $authError = 'Ошибка при регистрации';
            }
        }
    } elseif ($auth_action == 'logout') {
        // Используем проверку на существование ключа, чтобы избежать предупреждения
        $username = isset($_SESSION['user']) ? $_SESSION['user'] : null;
        session_unset();
        session_destroy();
        
        // Удаляем пользователя из списка онлайн, если он был аутентифицирован
        if ($username) {
            $onlineUsers = loadOnlineUsers();
            if (isset($onlineUsers[$username])) {
                unset($onlineUsers[$username]);
                saveOnlineUsers($onlineUsers);
            }
        }
        
        // Перенаправляем на эту же страницу, чтобы избежать повторной отправки формы
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($auth_action == 'clear_chat' && isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
        // Очищаем чат
        file_put_contents(DBFILE, '');
        echo "OK";
        exit;
    }
}

// Check if user is authenticated
$authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Обновляем активность пользователя если он авторизован
if ($authenticated && isset($_SESSION['user'])) {
    $onlineUsers = updateUserActivity($_SESSION['user']);
}

// Get current user for display - с проверкой на существование
$current_user = isset($_SESSION['user']) ? $_SESSION['user'] : '';
$is_admin = isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : false;

function makeURL( $matches ) {
    return '<a href="' . ( strpos( $matches[1], "://" ) === false ? "http://" : "" ) . $matches[1] . '" target="_blank">' . $matches[1] . '</a>';
}

function chatOut( $status = null, $chat = null ) {
    if ( $status !== null ) {
        $lastMod = filemtime( DBFILE );
        if ( $lastMod === false ) $lastMod = 0;
        echo( "{$status}:$lastMod\n" );
    }

    if ( $chat === null ) {
        if ( CHATTRIM ) {
            $f = fopen( DBFILE, "r" );
            fseek( $f, -CHATTRIM, SEEK_END );
            $chat = fread( $f, CHATTRIM );
            fclose( $f );
            $p =  strpos( $chat, '<div class="msg"' );
            if ( $p !== false ) {
                $chat = substr( $chat, $p );
            }
        }
        else $chat = file_get_contents( DBFILE );
        
        // Конвертируем содержимое чата в UTF-8, если нужно
        if (mb_detect_encoding($chat, 'UTF-8', true) === false) {
            $chat = iconv('Windows-1251', 'UTF-8', $chat);
        }
    }

    echo( $chat );
}

function cleanName( $str ) {
    $str = trim( $str );
    $str = preg_replace( "~[^ 0-9a-zа-яё]~iu", "", $str );
    $str = substr( $str, 0, MAXUSERNAMELEN );
    return $str;
}

function cleanText( $str ) {
    $str = trim( $str );
    $str = preg_replace( "~[^ \n\\!\"#$%&'\\(\\)\\*\\+,\\-\\.\\/0-9:;<=>\\?@a-z\\[\\\\\]\\^_`\\{\\|\\}\\~а-яё]~iu", "", $str );
    $str = preg_replace( "~\\r~", "", $str );
    $str = preg_replace( "~&~", "&amp;", $str );
    $str = preg_replace( "~<~", "&lt;", $str );
    $str = preg_replace( "~>~", "&gt;", $str );
    $str = preg_replace( "~\\n~", "<br />", $str );
    $str = substr( $str, 0, MAXUSERTEXTLEN );

    return $str;
}

// Функция для обработки загрузки медиафайлов
function handleMediaUpload($file, $username) {
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv'
    ];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Ошибка загрузки файла'];
    }
    
    // Проверяем тип файла
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!array_key_exists($mime, $allowedTypes)) {
        return ['success' => false, 'error' => 'Недопустимый тип файла'];
    }
    
    // Генерируем уникальное имя файла
    $extension = $allowedTypes[$mime];
    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $username) . '.' . $extension;
    $filepath = MEDIA_DIR . '/' . $filename;
    
    // Перемещаем файл в медиа-директорию
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'type' => $mime];
    }
    
    return ['success' => false, 'error' => 'Не удалось сохранить файл'];
}

// Only process chat functions if authenticated
if ($authenticated && isset($_SESSION['user'])) {
    $exit = false;

    $name = $_SESSION['user']; // Используем имя из сессии

    $text = isset($_POST["text"]) ? $_POST["text"] : null;

    $mode = null;
    if (isset($_POST["mode"])) {
        switch( $_POST["mode"] ) {
            case "post":
                $mode = "post";
                break;

            case "list":
                $mode = "list";
                break;
                
            case "online":
                $mode = "online";
                break;
        }
    }

    if ( $text ) $text = cleanText( $text );

    // Обработка загрузки медиафайлов
    $mediaResult = null;
    if ($mode == "post" && isset($_FILES['media']) && $_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE) {
        $mediaResult = handleMediaUpload($_FILES['media'], $name);
    }

    if ( $mode == "post" ) {
        if ( !$name || (!$text && !$mediaResult) ) {
            header( 'HTTP/1.1 400 Bad Request' );
            exit( 0 );
        }

        if ( !empty( $_SERVER[ "HTTP_CLIENT_IP" ] ) ) {
            $id = $_SERVER[ "HTTP_CLIENT_IP" ];
        } elseif ( !empty( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ) {
            $id = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $id = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : 'unknown';
        }

        $exit = true;

        $text = preg_replace_callback( "/((?:[a-z]+:\/\/(?:www\.)?|www\.)[a-z0-9\$_.+!*\'(),\/:@~=?&%-]+)/i", 'makeURL', $text );
        
        // Добавляем медиа в сообщение
        $mediaHtml = '';
        if ($mediaResult && $mediaResult['success']) {
            if (strpos($mediaResult['type'], 'image/') === 0) {
                $mediaHtml = '<div class="media-container"><img src="media/' . $mediaResult['filename'] . '" alt="Загруженное изображение" class="chat-media"></div>';
            } elseif (strpos($mediaResult['type'], 'video/') === 0) {
                $mediaHtml = '<div class="media-container">';
                $mediaHtml .= '<video class="chat-media" controls>';
                $mediaHtml .= '<source src="media/' . $mediaResult['filename'] . '" type="' . $mediaResult['type'] . '">';
                $mediaHtml .= 'Ваш браузер не поддерживает видео тег.';
                $mediaHtml .= '</video></div>';
            }
        }
        
        $msg = '<div class="msg"><div class="info"><span class="name">' . $name . '</span><span class="misc"><span class="date">' . date( "d.m.Y H:i:s" ) . '</span> <span class="id">(' . $id . ')</span></span></div>' . $text . $mediaHtml . '</div>' . "\n\n";

        // Конвертируем сообщение в UTF-8 перед сохранением
        if (mb_detect_encoding($msg, 'UTF-8', true) === false) {
            $msg = iconv('UTF-8', 'Windows-1251', $msg);
        }
        
        file_put_contents( DBFILE, $msg, FILE_APPEND );

        $mode = "list";
    }

    if ( $mode == "list" ) {
        $exit = true;

        $rlm = isset($_POST["lastMod"]) && preg_match( "~^\\d+$~", $_POST["lastMod"] ) ? (int)$_POST["lastMod"] : 0;

        $lastMod = filemtime( DBFILE );
        if ( $lastMod === false ) $lastMod = 0;

        if ( $rlm == $lastMod ) {
            chatOut( "NONMODIFIED", "" );
        } else {
            chatOut( "OK", null );
        }
    }
    
    if ( $mode == "online" ) {
        $exit = true;
        $onlineUsers = loadOnlineUsers();
        echo json_encode(array_keys($onlineUsers));
    }

    if ( $exit ) exit( 0 );

    $lastMod = filemtime( DBFILE );
    if ( $lastMod === false ) $lastMod = 0;
}

// Получаем список онлайн-пользователей
$onlineUsers = loadOnlineUsers();
$online_count = count($onlineUsers);

?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <title>ProtoChat</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

        <style type="text/css">
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            html, body {
                height: 100%;
                font-family: 'Roboto', Tahoma, Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #333;
                overflow-x: hidden;
            }

            #auth-container {
                max-width: 400px;
                margin: 50px auto;
                padding: 20px;
                background: white;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                animation: fadeIn 0.5s ease;
            }

            #auth-container h2 {
                text-align: center;
                margin-bottom: 20px;
                color: #764ba2;
            }

            .auth-form input {
                width: 100%;
                padding: 12px;
                margin: 8px 0;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
            }

            .auth-form button {
                width: 100%;
                padding: 12px;
                background: #667eea;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                margin-top: 10px;
                transition: background 0.3s;
            }

            .auth-form button:hover {
                background: #764ba2;
            }

            .auth-tabs {
                display: flex;
                margin-bottom: 20px;
            }

            .auth-tab {
                flex: 1;
                padding: 10px;
                text-align: center;
                cursor: pointer;
                background: #f1f1f1;
                border-radius: 5px 5px 0 0;
            }

            .auth-tab.active {
                background: #667eea;
                color: white;
            }

            .auth-error {
                color: #e74c3c;
                text-align: center;
                margin: 10px 0;
            }

            #wrapper {
                width: 100%;
                max-width: 800px;
                margin: 0 auto;
                padding: 15px;
                display: <?php echo $authenticated ? 'block' : 'none'; ?>;
            }

            h1 {
                text-align: center;
                color: white;
                margin: 15px 0;
                text-shadow: 0 2px 5px rgba(0,0,0,0.3);
                font-size: 2.2rem;
            }

            .chat-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                color: white;
            }

            .user-info {
                font-weight: bold;
            }

            .logout-btn {
                background: rgba(255,255,255,0.2);
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 5px;
                cursor: pointer;
                transition: background 0.3s;
            }

            .logout-btn:hover {
                background: rgba(255,255,255,0.3);
            }

            .clear-chat-btn {
                background: rgba(231, 76, 60, 0.2);
                color: #e74c3c;
                border: none;
                padding: 8px 15px;
                border-radius: 5px;
                cursor: pointer;
                transition: background 0.3s;
                margin-left: 10px;
            }

            .clear-chat-btn:hover {
                background: rgba(231, 76, 60, 0.3);
            }

            #msgsDialog {
                background: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                margin-bottom: 20px;
                overflow: hidden;
                animation: slideUp 0.5s ease;
            }

            #msgsContent {
                height: 50vh;
                padding: 15px;
                overflow-y: auto;
                background: #fafafa;
                scroll-behavior: smooth;
            }

            #msgsContent .msg {
                margin-bottom: 15px;
                padding: 12px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
                animation: msgAppear 0.3s ease;
                transition: transform 0.2s;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }

            #msgsContent .msg:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }

            #msgsContent .msg .info {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
                font-size: 0.9em;
                color: #666;
                flex-wrap: wrap;
            }

            #msgsContent .msg .info .name {
                font-weight: bold;
                color: #667eea;
            }

            #msgsContent .msg .info .misc {
                font-size: 0.85em;
            }

            #msgsContent .msg a {
                color: #667eea;
                word-break: break-all;
            }

            .media-container {
                margin-top: 10px;
                max-width: 100%;
                overflow: hidden;
                border-radius: 8px;
                background: #000;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            .chat-media {
                max-width: 100%;
                max-height: 300px;
                object-fit: contain;
            }

            #sendDialog {
                background: white;
                padding: 15px;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                animation: slideUp 0.5s ease 0.2s both;
            }

            #sendDialog textarea {
                width: 100%;
                padding: 12px;
                margin-bottom: 10px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-family: inherit;
                font-size: 16px;
                resize: none;
                min-height: 100px;
                box-sizing: border-box;
            }

            #media-preview {
                margin-bottom: 10px;
                text-align: center;
                display: none;
            }

            #media-preview img, #media-preview video {
                max-width: 100%;
                max-height: 200px;
                border-radius: 8px;
            }

            .buttons-container {
                display: flex;
                gap: 10px;
                align-items: center;
            }

            #submit {
                background: #667eea;
                color: white;
                border: none;
                padding: 12px 20px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                transition: background 0.3s, transform 0.2s;
                flex: 2;
            }

            #submit:hover {
                background: #764ba2;
                transform: translateY(-2px);
            }

            #submit:active {
                transform: translateY(0);
            }

            #media-button {
                background: #4CAF50;
                color: white;
                border: none;
                padding: 12px 15px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                transition: background 0.3s, transform 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
                flex: 1;
            }

            #media-button:hover {
                background: #45a049;
                transform: translateY(-2px);
            }

            #media-button:active {
                transform: translateY(0);
            }

            .remove-media {
                background: #e74c3c;
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
            }

            /* Animations */
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            @keyframes slideUp {
                from { 
                    opacity: 0;
                    transform: translateY(20px);
                }
                to { 
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @keyframes msgAppear {
                from {
                    opacity: 0;
                    transform: scale(0.95);
                }
                to {
                    opacity: 1;
                    transform: scale(1);
                }
            }

            /* Responsive styles */
            @media (max-width: 768px) {
                #wrapper {
                    padding: 10px;
                }
                
                h1 {
                    font-size: 1.8rem;
                    margin: 10px 0;
                }
                
                #msgsContent {
                    height: 60vh;
                    padding: 10px;
                }
                
                #msgsContent .msg {
                    padding: 10px;
                    margin-bottom: 10px;
                }
                
                #msgsContent .msg .info {
                    flex-direction: column;
                }
                
                #sendDialog {
                    padding: 10px;
                }
                
                .buttons-container {
                    flex-direction: column;
                }
                
                #submit, #media-button {
                    width: 100%;
                }
                
                .chat-header {
                    flex-direction: column;
                    gap: 10px;
                }
            }

            @media (max-width: 480px) {
                #auth-container {
                    margin: 20px;
                    width: auto;
                }
                
                h1 {
                    font-size: 1.5rem;
                }
                
                #msgsContent {
                    height: 55vh;
                }
                
                #msgsContent .msg .info .misc {
                    font-size: 0.75em;
                }
            }

            .online-users {
                background: white;
                padding: 10px;
                border-radius: 8px;
                margin-bottom: 15px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                animation: fadeIn 0.5s ease;
            }

            .online-users h3 {
                margin-bottom: 8px;
                color: #667eea;
                font-size: 1rem;
            }

            .user-count {
                display: inline-block;
                background: #667eea;
                color: white;
                border-radius: 50%;
                width: 24px;
                height: 24px;
                text-align: center;
                line-height: 24px;
                margin-left: 5px;
            }

            .online-user {
                display: inline-block;
                background: #f1f1f1;
                padding: 4px 8px;
                border-radius: 12px;
                margin: 2px;
                font-size: 0.9em;
            }
        </style>
    </head>
    <body>
        <?php if (!$authenticated): ?>
        <div id="auth-container">
            <h2>Добро пожаловать в ProtoChat</h2>
            
            <div class="auth-tabs">
                <div class="auth-tab active" id="login-tab">Вход</div>
                <div class="auth-tab" id="register-tab">Регистрация</div>
            </div>
            
            <?php if ($authError): ?>
            <div class="auth-error"><?php echo $authError; ?></div>
            <?php endif; ?>
            
            <form class="auth-form" id="login-form" method="post">
                <input type="hidden" name="auth_action" value="login">
                <input type="text" name="login" placeholder="Логин" required>
                <input type="password" name="password" placeholder="Пароль" required>
                <button type="submit">Войти</button>
            </form>
            
            <form class="auth-form" id="register-form" method="post" style="display: none;">
                <input type="hidden" name="auth_action" value="register">
                <input type="text" name="login" placeholder="Логин" required>
                <input type="password" name='password' placeholder="Пароль" required>
                <input type="password" name="confirm" placeholder="Подтвердите пароль" required>
                <button type="submit">Зарегистрироваться</button>
            </form>
        </div>
        <?php endif; ?>

        <div id="wrapper">
            <div class="chat-header">
                <h1><?php echo( HEADER ); ?></h1>
                <div>
                    <?php if ($authenticated): ?>
                    <span class="user-info"><?php echo $current_user; ?></span>
                    <div>
                        <button class="logout-btn" onclick="logout()">Выйти</button>
                        <?php if ($is_admin): ?>
                        <button class="clear-chat-btn" onclick="clearChat()">Очистить чат</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($authenticated): ?>
            <div class="online-users">
                <h3>Онлайн <span class="user-count" id="user-count"><?php echo $online_count; ?></span></h3>
                <div id="users-list">
                    <?php foreach ($onlineUsers as $user => $time): ?>
                        <span class="online-user"><?php echo $user; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="msgsDialog">
                <div id="msgsContent">
                    <?php chatOut(); ?>
                </div>
            </div>

            <form action="" method="post" id="sendForm" enctype="multipart/form-data">
                <div id="sendDialog">
                    <input type="hidden" name="name" value="<?php echo $current_user; ?>" />
                    <textarea name="text" placeholder="Ваше сообщение" maxlength="<?php echo( MAXUSERTEXTLEN ); ?>"></textarea>
                    
                    <div id="media-preview"></div>
                    
                    <div class="buttons-container">
                        <input type="file" id="media-upload" name="media" accept="image/*,video/*" style="display: none;">
                        <button type="button" id="media-button" onclick="document.getElementById('media-upload').click()">
                            📎 Медиа
                        </button>
                        <input type="submit" value="Отправить" class="button" title="ctrl + enter" id="submit"/>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
            // Функция для переключения вкладок авторизации
            function initAuthTabs() {
                var loginTab = document.getElementById('login-tab');
                var registerTab = document.getElementById('register-tab');
                var loginForm = document.getElementById('login-form');
                var registerForm = document.getElementById('register-form');
                
                if (loginTab && registerTab && loginForm && registerForm) {
                    loginTab.addEventListener('click', function() {
                        this.classList.add('active');
                        registerTab.classList.remove('active');
                        loginForm.style.display = 'block';
                        registerForm.style.display = 'none';
                    });

                    registerTab.addEventListener('click', function() {
                        this.classList.add('active');
                        loginTab.classList.remove('active');
                        loginForm.style.display = 'none';
                        registerForm.style.display = 'block';
                    });
                }
            }

            // Инициализация при загрузке страницы
            document.addEventListener('DOMContentLoaded', function() {
                initAuthTabs();
                
                // Обработчик загрузки медиафайлов
                var mediaUpload = document.getElementById('media-upload');
                if (mediaUpload) {
                    mediaUpload.addEventListener('change', function(e) {
                        if (this.files && this.files[0]) {
                            var file = this.files[0];
                            var preview = document.getElementById('media-preview');
                            
                            preview.style.display = 'block';
                            
                            if (file.type.startsWith('image/')) {
                                var reader = new FileReader();
                                reader.onload = function(e) {
                                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Превью"><button type="button" class="remove-media" onclick="removeMedia()">Удалить</button>';
                                }
                                reader.readAsDataURL(file);
                            } else if (file.type.startsWith('video/')) {
                                var video = document.createElement('video');
                                video.controls = true;
                                video.src = URL.createObjectURL(file);
                                video.style.maxWidth = '100%';
                                video.style.maxHeight = '200px';
                                preview.innerHTML = '';
                                preview.appendChild(video);
                                preview.innerHTML += '<button type="button" class="remove-media" onclick="removeMedia()">Удалить</button>';
                            }
                        }
                    });
                }

                // Автоматическая прокрутка к последнему сообщению при загрузке
                scrollToBottom();
            });

            function removeMedia() {
                document.getElementById('media-upload').value = '';
                document.getElementById('media-preview').style.display = 'none';
                document.getElementById('media-preview').innerHTML = '';
            }

            function logout() {
                var form = document.createElement('form');
                form.method = 'post';
                form.action = '';
                
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'auth_action';
                input.value = 'logout';
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }

            function clearChat() {
                if (confirm('Вы уверены, что хотите очистить весь чат? Это действие нельзя отменить.')) {
                    var formData = new FormData();
                    formData.append('auth_action', 'clear_chat');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        if (response.ok) {
                            // Обновляем чат
                            refresh({ mode: "list" }, function() {});
                            alert('Чат успешно очищен!');
                        } else {
                            alert('Ошибка при очистке чата!');
                        }
                    })
                    .catch(function(error) {
                        alert('Ошибка при очистке чата: ' + error);
                    });
                }
            }

            function scrollToBottom() {
                var msgs = document.getElementById('msgsContent');
                if (msgs) {
                    msgs.scrollTop = msgs.scrollHeight;
                }
            }

            function updateOnlineUsers() {
                fetch('?mode=online')
                    .then(response => response.json())
                    .then(users => {
                        var userCountElem = document.getElementById('user-count');
                        var usersListElem = document.getElementById('users-list');
                        
                        if (userCountElem) userCountElem.textContent = users.length;
                        
                        if (usersListElem) {
                            usersListElem.innerHTML = '';
                            users.forEach(function(user) {
                                var userEl = document.createElement('span');
                                userEl.className = 'online-user';
                                userEl.textContent = user;
                                usersListElem.appendChild(userEl);
                            });
                        }
                    });
            }

            // Original chat functionality with enhancements
            (function() {
                <?php if (!$authenticated) { echo 'return;'; } // Don't initialize chat if not authenticated ?>
                
                var msgsDialog = document.getElementById( "msgsDialog" );
                var sendDialog = document.getElementById( "sendDialog" );
                var submit = document.getElementById( "submit" );

                var msgs = document.getElementById( "msgsContent" );
                var f = document.getElementById( "sendForm" );
                var text = f.elements.text;

                // Initialize chat functionality
                var lastMod = "<?php echo $authenticated ? $lastMod : '0'; ?>";
                
                function refresh( params, handler ) {
                    if ( !params ) params = {};
                    if ( !params.hasOwnProperty( "lastMod" ) ) params.lastMod = lastMod;

                    // Обновляем онлайн пользователей
                    updateOnlineUsers();

                    var formData = new FormData();
                    for (var key in params) {
                        if (params.hasOwnProperty(key)) {
                            formData.append(key, params[key]);
                        }
                    }

                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        return response.text();
                    })
                    .then(function(txt) {
                        if (txt !== undefined) {
                            var p = txt.indexOf( "\n" );
                            if ( p > 0 ) {
                                var s = /^([a-z]+):(\d+)$/i.exec( txt.substring( 0, p ) ), lm;
                                if ( s ) {
                                    lm = s[2];
                                    s = s[1];

                                    txt = txt.substring( p + 1 );

                                    if ( s == "NONMODIFIED" ) txt = undefined;
                                    if ( s == "OK" ) lastMod = lm;
                                }
                            }

                            if ( txt !== undefined ) {
                                // Add animation to new messages
                                var tempDiv = document.createElement('div');
                                tempDiv.innerHTML = txt;
                                var newMessages = tempDiv.querySelectorAll('.msg');
                                
                                for (var i = 0; i < newMessages.length; i++) {
                                    newMessages[i].style.animation = 'msgAppear 0.3s ease';
                                }
                                
                                msgs.innerHTML = txt;
                                
                                // Всегда прокручиваем к последнему сообщению
                                setTimeout(scrollToBottom, 100);
                            }
                        }

                        if ( handler ) handler( true, 200, txt );
                    })
                    .catch(function(error) {
                        if ( handler ) handler( false, 500, error );
                    });
                }

                var poll = (function() {
                    var t = null;
                    var inProgress = false;

                    var rq = function() {
                        if ( inProgress ) return;

                        inProgress = true;
                        refresh(
                            { mode: "list" },
                            function( state, status, txt ) {
                                inProgress = false;
                                poll( false, true );
                            }
                        );
                    };

                    return function( refreshNow, rewait ) {
                        if ( rewait === true ) {
                            if ( t ) clearTimeout( t );
                            t = setTimeout( rq, <?php echo( REFRESHTIME ); ?> );
                        }

                        if ( refreshNow === true ) rq();
                    };
                })();

                f.onsubmit = function() {
                    if ( /^\s*$/.test( text.value ) && !document.getElementById('media-upload').value ) {
                        alert( "Пожалуйста, введите сообщение или добавьте медиафайл" );
                        return false;
                    }

                    // Используем FormData для отправки файлов
                    var formData = new FormData(this);
                    formData.append('mode', 'post');
                    formData.append('name', '<?php echo $current_user; ?>');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        if (response.ok) {
                            text.value = "";
                            removeMedia();
                            refresh({ mode: "list" }, function() {});
                        }
                    });

                    return false;
                };

                text.onkeydown = function( e ) {
                    if ( !e ) e = window.event;
                    if ( e.keyCode === 13 && e.ctrlKey ) {
                        f.onsubmit();
                    }
                };

                // Прокручиваем к последнему сообщению при загрузке
                setTimeout(scrollToBottom, 500);
                text.focus();
                poll( false, true );
                
                // Обновляем онлайн пользователей каждые 10 секунд
                setInterval(updateOnlineUsers, 10000);
            })();
        </script>
    </body>
</html>