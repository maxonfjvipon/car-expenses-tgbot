<?php
// https://api.telegram.org/bot5183618755:AAFmE7z8jLYQkBDOL-dDnUbhuviuu6LRMVA/setwebhook?url=https://bpeltd.ru/maxonfjvipon/index.php

const TOKEN = "5183618755:AAFmE7z8jLYQkBDOL-dDnUbhuviuu6LRMVA";

$data = json_decode(file_get_contents('php://input'), TRUE);
file_put_contents(__DIR__ . '/file.txt', '$data: ' . print_r($data, 1) . "\n", FILE_APPEND);

$send_data = [];

$method = "sendMessage";

$connection = mysqli_connect('localhost', 'parfnic_tgbot', 'Maximtrun19', 'parfnic_tgbot');

if (!$connection) {
    badDatabase();
}

$state = getLastCommand($connection);

$data = $data['callback_query'] ?? $data['message'];

define("CHAT_ID", $data['chat']['id']);

$message = $data['text'] ?? $data['data'];

$commands = [
    'start' => '/start',
    'new_car' => '/new_car',
    'pickup_car' => '/pickup_car',
    'fuel_up' => '/fuel_up',
    'statistics' => '/statistics'
];

if ($state == null) {
    switch ($message) {
        case $commands['start']:
            $send_data = sendDataWithText('Привет! Я Бот для учета расхода топлива на авто. Я могу хранить информацию о нескольких твоих автомобилях.');
            break;
        case $commands['new_car']:
            $send_data = sendDataWithText('Давай добавим новое авто. Как хочешь, чтобы я его называл?');
            storeCommand($connection, $commands['new_car'], 1, 0);
            break;
        case $commands['pickup_car']:
            $cars = query($connection, "select id, name, year, mileage from cars order by id");
            $rows = mysqli_fetch_all($cars, MYSQLI_ASSOC);
            if (count($rows) === 0) {
                $send_data = sendDataWithText("У вас еще нет заведенных авто. Чтобы добавить вызовите команду /new_car");
            } else {
                $min_id = $rows[0]['id'];
                $send_data = [
                    'text' => 'Выберите авто',
                    'reply_markup' => [
                        'resize_keyboard' => true,
                        'keyboard' => [
                            array_map(fn(array $row) => [
                                'text' => ($row['id'] - $min_id + 1)
                                    . ': ' . $row['name']
                                    . ', ' . $row['year']
                                    . ' год, пробег '
                                    . $row['mileage'] . ' км'
                            ], $rows)
                        ],
                        'one_time_keyboard' => true
                    ]
                ];
                storeCommand($connection, $commands['pickup_car'], 1, $min_id);
            }
            break;
        case $commands['fuel_up']:
            $cars = query($connection, "select id, name, year, mileage from cars order by id");
            $rows = mysqli_fetch_all($cars, MYSQLI_ASSOC);
            if (count($rows) === 0) {
                $send_data = sendDataWithText("У вас еще нет заведенных авто. Чтобы добавить вызовите команду /new_car");
            } else {
                $send_data = sendDataWithText("Гляжу, ты заправился! Напиши мне, сколько ты потратил");
                $cars = query($connection, "select id from cars where current = 1;");
                $rows = mysqli_fetch_all($cars, MYSQLI_ASSOC);
                if (count($rows) !== 1) {
                    badDatabase("Текущих машин не одна");
                }
                $car_id = $rows[0]['id'];
                query($connection, "insert into fuelups (car_id, cost, mileage, quantity) values ($car_id, 0, 0, 0);");
                $fuel_up_id = mysqli_insert_id($connection);
                storeCommand($connection, $commands['fuel_up'], 1, $fuel_up_id);
            }
            break;
        case $commands['statistics']:
            $cars = query($connection, "select id, name, year, mileage from cars order by id");
            $rows = mysqli_fetch_all($cars, MYSQLI_ASSOC);
            if (count($rows) === 0) {
                $send_data = sendDataWithText("У вас еще нет заведенных авто. Чтобы добавить вызовите команду /new_car");
            } else {
                $car = query($connection, "select id, name, year, mileage from cars where current = 1;");
                $rows = mysqli_fetch_all($car, MYSQLI_ASSOC);
                if (count($rows) !== 1) {
                    badDatabase("Нет текущей машины");
                }
                $car = $rows[0];
                $fuelups = query($connection, "select cost, mileage, quantity from fuelups where car_id = " . $car['id'] . " order by mileage;");
                $fuelups = mysqli_fetch_all($fuelups, MYSQLI_ASSOC);
                if (count($fuelups) === 0) {
                    $send_data = sendDataWithText("Для текущей машины пока нет заправок!");
                } else {
                    $total_cost = 0;
                    $total_quantity = 0;
                    foreach ($fuelups as $fuelup) {
                        $total_cost += $fuelup['cost'];
                        $total_quantity += $fuelup['quantity'];
                    }
                    $mileage_diff = $fuelups[count($fuelups) - 1]['mileage'] - $fuelups[0]['mileage'];
                    $send_data = sendDataWithText(
                        "Итак, " . $car['name']
                        . ", " . $car['year']
                        . ", пробег " . $car['mileage']
                        . "\nВсего потрачено на заправки: $total_cost руб."
                        . "\nВсего пройдено: $mileage_diff км"
                        . "\nСредний расход: " . ($total_quantity / $mileage_diff * 100) . "л/100км"
                        . "\nСтоимость 1км: " . ($total_cost / $mileage_diff) . " руб."
                    );
                }
            }
            break;
        case "/cancel":
            $send_data = sendDataWithText("Ни одна команда не исполняется");
            break;
        default:
            $send_data = sendDataWithText('Не понимаю о чем ты =(');
    }
} else {
    if (in_array($message, $commands)) {
        $send_data = sendDataWithText('Закончи предыдущую команду прежде, чем перейти к следующей!');
        if ($state['command'] === $commands['pickup_car']) {
            $send_data['reply_markup'] = ['resize_keyboard' => true];
        }
    } else {
        switch ($state['command']) {
            case $commands['new_car']:
                switch ($state['step']) {
                    case 1:
                        if (commandIsCancel($message)) {
                            dropCommand($connection);
                            $send_data = sendDataCancel();
                        } else {
                            $send_data = sendDataWithText("Отлично! Я буду звать ее " . $message . ".\nКакого она года выпуска?");
                            query($connection, "insert into cars (name, year, mileage) values ('" . $message . "', 0, 0);");
                            $car_id = mysqli_insert_id($connection);
                            newCommand($connection, $commands['new_car'], 2, $car_id);
                        }
                        break;
                    case 2:
                        if (commandIsCancel($message)) {
                            $car_id = $state['value'];
                            query($connection, "delete from cars where id = $car_id");
                            dropCommand($connection);
                            $send_data = sendDataCancel();
                        } else {
                            $year = intval($message);
                            if ($year < 1900 || $year > 2022) {
                                $send_data = sendDataWithText("Это не похоже на год выпуска! Попробуй еще раз!");
                            } else {
                                $send_data = sendDataWithText("Прекрасно! Каков ее текущий пробег?");
                                $car_id = $state['value'];
                                query($connection, "update cars set year = $message where id = $car_id;");
                                newCommand($connection, $commands['new_car'], 3, $car_id);
                            }
                        }
                        break;
                    case 3:
                        if (commandIsCancel($message)) {
                            $car_id = $state['value'];
                            query($connection, "delete from cars where id = $car_id");
                            dropCommand($connection);
                            $send_data = sendDataCancel();
                        } else {
                            if (!is_numeric($message)) {
                                $send_data = sendDataWithText("Это не похоже на пробег! Попробуй еще раз!");
                            } else {
                                $mileage = intval($message);
                                $send_data = sendDataWithText("Супер! Все записал, эта машина установлена как текущая.");
                                $car_id = $state['value'];
                                query($connection, "update cars set current = 0 where id <> $car_id;");
                                query($connection, "update cars set mileage = $message, current = 1 where id = $car_id;");
                                dropCommand($connection);
                            }
                        }
                        break;
                }
                break;
            case $commands['pickup_car']:
                if (commandIsCancel($message)) {
                    $send_data = sendDataCancel();
                } else {
                    $id = explode(":", $message)[0] + $state['value'] - 1;
                    query($connection, "update cars set current = 0 where id <> $id;");
                    query($connection, "update cars set current = 1 where id = $id;");
                    $send_data = [
                        'text' => "Отлично. Теперь эта машина установлена как текущая",
                        'reply_markup' => ['hide_keyboard' => true],
                    ];
                }
                dropCommand($connection);
                break;
            case $commands['fuel_up']:
                switch ($state['step']) {
                    case 1:
                        if (commandIsCancel($message)) {
                            $fuel_up_id = $state['value'];
                            query($connection, "delete from fuelups where id = $fuel_up_id");
                            dropCommand($connection);
                            $send_data = sendDataCancel();
                        } else {
                            if (!is_numeric($message)) {
                                $send_data = sendDataWithText("Это не похоже на стоимость! Попробуй еще раз!");
                            } else {
                                $cost = intval($message);
                                $send_data = sendDataWithText("Отлично! Сколько литров ты залил?");
                                $fuel_up_id = $state['value'];
                                query($connection, "update fuelups set cost = $cost where id = $fuel_up_id");
                                newCommand($connection, $commands['fuel_up'], 2, $fuel_up_id);
                            }
                        }
                        break;
                    case 2:
                        if (commandIsCancel($message)) {
                            $fuel_up_id = $state['value'];
                            query($connection, "delete from fuelups where id = $fuel_up_id");
                            dropCommand($connection);
                            $send_data = sendDataCancel();
                        } else {
                            if (!is_numeric($message)) {
                                $send_data = sendDataWithText("Это не похоже на количество литров! Попробуй еще раз!");
                            } else {
                                $quantity = intval($message);
                                $send_data = sendDataWithText("Прекрасно! Какой пробег сейчас у авто?");
                                $fuel_up_id = $state['value'];
                                query($connection, "update fuelups set quantity = $quantity where id = $fuel_up_id");
                                newCommand($connection, $commands['fuel_up'], 3, $fuel_up_id);
                            }
                        }
                        break;
                    case 3:
                        if (commandIsCancel($message)) {
                            $fuel_up_id = $state['value'];
                            query($connection, "delete from fuelups where id = $fuel_up_id");
                            dropCommand($connection);
                            $send_data = sendDataCancel();
                        } else {
                            if (!is_numeric($message)) {
                                $send_data = sendDataWithText("Это не похоже на пробег! Попробуй еще раз!");
                            } else {
                                $mileage = intval($message);
                                $fuel_up_id = $state['value'];
                                $car_id = query($connection, "select car_id from fuelups where id = $fuel_up_id;");
                                $rows = mysqli_fetch_all($car_id, MYSQLI_ASSOC);
                                if (count($rows) !== 1) {
                                    badDatabase("Не удалось достать car_id из fuelups по id");
                                }
                                $car_id = $rows[0]['car_id'];
                                $prev_mileage = query($connection, "select mileage from cars where id = $car_id;");
                                if (count($rows = mysqli_fetch_all($prev_mileage, MYSQLI_ASSOC)) !== 1) {
                                    badDatabase("Не удалось достать пробег по car_id");
                                }
                                $prev_mileage = $rows[0]['mileage'];
                                if ($prev_mileage > $mileage) {
                                    $send_data = sendDataWithText("Этот пробег меньше того, что ты указывал ранее. Попробуй еще раз");
                                } else {
                                    $send_data = sendDataWithText("Супер! Я все сохранил!");
                                    query($connection, "update cars set mileage = $mileage where id = $car_id;");
                                    query($connection, "update fuelups set mileage = $mileage where id = $fuel_up_id");
                                    dropCommand($connection);
                                }
                            }
                        }
                        break;
                }
                break;
            case $commands['statistics']:
                break;
        }
    }
}

$res = sendTelegram($method, $send_data);

function sendDataWithText(string $text): array
{
    return ['text' => $text];
}

// send data for /cancel command
function sendDataCancel(): array
{
    return sendDataWithText("Команда отменена");
}

// if command is cancel
function commandIsCancel($message): bool
{
    return $message === "/cancel";
}

// clear state
function dropCommand(mysqli $connection)
{
    return query($connection, "delete from state;");
}

// get last command from database
function getLastCommand(mysqli $connection)
{
    $res = query($connection, "select command, step, value from state;");
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    if (count($rows) === 0) {
        return null;
    }
    if (count($rows) > 1) {
        badDatabase();
    }
    return $rows[0];
}

// drop old, store new
function newCommand(mysqli $connection, string $command, int $step, $value)
{
    dropCommand($connection);
    return storeCommand($connection, $command, $step, $value);
}

// store command
function storeCommand(mysqli $connection, string $command, int $step, $value)
{
    $sql = "insert into state (command, step, value) values (\"$command\",$step, \"$value\");";
    puttofile($sql);
    return query($connection, $sql);
}

function query($connection, $query)
{
    $res = mysqli_query($connection, $query);
    if (!$res) {
        badDatabase($query);
    }
    puttofile($res);
    return $res;
}

// if something wrong with database
function badDatabase(string $addInfo = "")
{
    puttofile("Something wrong with database: " . $addInfo);
    sendTelegram("sendMessage", ['text' => 'Что-то пошло не так с базой данных. Прости(((']);
    die;
}

function puttofile($data)
{
    file_put_contents(__DIR__ . '/dump.txt', print_r($data, 1), FILE_APPEND);
}

// send message to telegram
function sendTelegram($method, $data, $headers = [])
{
    $data['chat_id'] = CHAT_ID;
    $data['reply_markup'] = $data['reply_markup'] ?? ['hide_keyboard' => true];
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => 'https://api.telegram.org/bot' . TOKEN . '/' . $method,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array_merge(["Content-Type: application/json"], $headers)
    ]);

    $result = curl_exec($curl);
    curl_close($curl);
    return (json_decode($result, 1) ?? $result);
}

