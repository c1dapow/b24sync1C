<?php

//  php test.php -m syncOperations -a C:\Users\user\Desktop\123\ Запуск скрипта из консоли пример
    // test.php - название файла, syncOperations - название метода, C:\Users\user\Desktop\123\ - аргумент метода(путь до файла C:\Users\user\Desktop\123\test.csv)
date_default_timezone_set('Asia/Novosibirsk');
// Парсинг аргументов командной строки
$options = getopt("m:a:", ["method:", "arg:"]);

$method = $options['m'] ?? $options['method'] ?? null;
$argument = $options['a'] ?? $options['arg'] ?? null;


if (!is_dir(realpath(__DIR__) . '\bitrix')) {
    mkdir(realpath(__DIR__) . '\bitrix', 0777, true); // PHP создаст папку от своего имени
}

if ($method && $argument) {
    echo "Метод: $method\n";
    echo "Аргумент: $argument\n";

    // Вызов метода
    if (function_exists($method)) {
        $result = $method($argument);
        echo "Результат: $result\n";
    }
}



function fixDate($dateString) {
    // Удаляем все кроме цифр и точек
    $clean = preg_replace('/[^\d\.]/', '', $dateString);

    // Разбиваем
    $parts = explode('.', $clean);

    // Проверяем
    if (count($parts) !== 3) {
        return date('d.m.Y'); // или выбросить исключение
    }

    // Форматируем
    $day = str_pad((int)$parts[0], 2, '0', STR_PAD_LEFT);
    $month = str_pad((int)$parts[1], 2, '0', STR_PAD_LEFT);
    $year = (int)$parts[2];

    // Корректируем год
    if ($year < 100) {
        $year = $year < 70 ? 2000 + $year : 1900 + $year;
    }

    return "{$day}.{$month}.{$year}";
}


    //Подключение к б24, в аргумены передаем метод и массив данных
    function CurlBitrix24($method, $arData = array())
    {
        $queryUrl = "YOUR_WEBHOOK_URL" . $method;

        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $queryUrl,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_POST => 1,
                CURLOPT_HEADER => 0,
                CURLOPT_RETURNTRANSFER => 1,
            ));


            if (!empty($arData)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($arData));
            }

            $result = curl_exec($curl);
            //curl_close($curl);
        }catch (\Exception $e)
        {
            $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в подключению к Битрикс24\n" . $e->getMessage();
            file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
        }


        return json_decode($result, true);
    };

    // Получение операции по ID заявки на расчет и ID операции из справочника
    function getOperation($calcID, $operation, $data = array())
    {
        $error = '';

        try {
            $operationData = CurlBitrix24(
            'lists.element.get',
            [
                'IBLOCK_TYPE_ID' => 'lists',
                'IBLOCK_ID' => 60,
                'ELEMENT_ID' => $operation,
                'SELECT' => [],
            ]
            )['result'][0];


            // Получение сделки по ID заявки на расчет
            $dealID = CurlBitrix24('crm.deal.list.json', array(
                'select' => ['ID'],
                'filter' => ['CATEGORY_ID' => 1, 'UF_CALC_REQUEST_ID' => $calcID],
                'order' => []
            ))['result'][0]['ID'];

            // ufOperation - id из справочника операций
            if ($dealID) {

                $comment = $data['doc'].' '.$data['comment'];
                $formateddate = date('Y-m-d', strtotime($data['date']));
                $sum = (int)$data['sum'];

                $duplicate = CurlBitrix24('crm.item.list', array(
                    'entityTypeId' => 1034,
                    'select' => ['ID', 'ufPriceFact', 'ufDateFact', 'ufNote', 'ufOperation'],
                    'filter' => [
                        'parentId2' => $dealID,
                        'ufOperation' => $operation,
                        'ufPriceFact' => $sum . '|RUB',
                        'ufDateFact' => $data['date'],
                        '%ufNote' => $data['doc']
                    ],
                ))['result']['items'];

                if ($duplicate)
                {
                    $errorMessage = date('Y-m-d H:i:s') . " - Такая запись уже имеется!\n";
                    $error = date('Y-m-d H:i:s') . " - Запись не внесена - Операция уже имеется - " . $calcID . " - " . $operationData['NAME'] . " - " . $data['date'] . " - " . $data['sum'] . " - " . $data['doc'] . " - " . $data['comment'] . "\n";
                    file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                    $info['error'] = $error;
                    $info['item'] = null;
                    return $info;
                }
                //Получение операций привязанных к сделке
                $result = CurlBitrix24('crm.item.list', array(
                    'entityTypeId' => 1034,
                    'select' => ['ID', 'ufPriceFact', 'ufDateFact', 'ufNote', 'ufOperation'],
                    'filter' => [
                        'parentId2' => $dealID,
                        'ufOperation' => $operation
                    ],
                ))['result']['items'];
            } else {
                $errorMessage = date('Y-m-d H:i:s') . " - Нету сделки с таким ID заявки на расчет - " . $calcID . "\n";
                $error = date('Y-m-d H:i:s') . " - Запись не внесена - Нету сделки с таким Номером заявки на расчет - " . $calcID . " - " . $operationData['NAME'] . " - " . $data['date'] . " - " . $data['sum'] . " - " . $data['doc'] . " - " . $data['comment'] . "\n";
                file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                $info['error'] = $error;
                $info['item'] = null;
                return $info;
            }

            if (!$result) {
                $errorMessage = date('Y-m-d H:i:s') . " - Такой операции нет!\n";
                $error = date('Y-m-d H:i:s') . " - Запись не внесена - Такой операции нет - " . $calcID . " - " . $operationData['NAME'] . " - " . $data['date'] . " - " . $data['sum'] . " - " . $data['doc'] . " - " . $data['comment'] . "\n";
                file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                $info['error'] = $error;
                $info['item'] = null;
                return $info;
            }
            if (count($result) > 1)
            {
                foreach ($result as $key => $item) {
                    // Проверка на заполненость полей в операции, при заполненности не даем изменять
                    if ($item['ufDateFact'] || $item['ufPriceFact']) {
                        $contain = str_contains($item['ufNote'], $data['doc']);
                        $sum = preg_replace('/[^0-9.]/', '', $item['ufPriceFact']);
                        if (date('d.m.Y', strtotime($item['ufDateFact'])) == $data['date'] && $contain) {
                            if ($sum == $data['sum'])
                            {
                                $errorMessage = date('Y-m-d H:i:s') . ' - Такая операция уже есть (' . $calcID . ' - ' . $item['ufOperation'] . ") \n";
                                file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                                unset($result[$key]);
                                continue;
                            }else {
                                $info['item'] = $item;
                                $info['item']['name'] = $operationData['NAME'];
                                $info['item']['rewrite'] = true;
                                $info['error'] = null;
                                return $info;
                            }
                        }elseif (!$item['ufDateFact']) {
                            $info['item'] = $item;
                            $info['item']['name'] = $operationData['NAME'];
                            $info['item']['rewrite'] = true;
                            $info['error'] = null;
                            return $info;
                        }else {
                            $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!3(' . $calcID . ' - ' . $item['ufOperation'] . ") \n";
                            file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                            unset($result[$key]);
                            continue;
                        }
                    }else {
                        $info['item'] = $item;
                        $info['item']['name'] = $operationData['NAME'];
                        $info['error'] = null;
                        return $info;
                    }
                }
                if (!$result) {
                    $error = date('Y-m-d H:i:s') . " - Запись не внесена - Свободной оперции для этого платежа нет - " . $calcID . " - " . $operationData['NAME'] . " - " . $data['date'] . " - " . $data['sum'] . " - " . $data['doc'] . " - " . $data['comment'] . "\n";
                    $info['item'] = null;
                    $info['error'] = $error;
                    return $info;
                }
            }else {
                if ($result[0]['ufDateFact'] || $result[0]['ufPriceFact']) {
                    $contain = str_contains($result[0]['ufNote'], $data['doc']);
                    $sum = preg_replace('/[^0-9.]/', '', $result[0]['ufPriceFact']);
                    if (date('d.m.Y', strtotime($result[0]['ufDateFact'])) == $data['date'] && $contain) {
                        if ($sum == $data['sum'])
                        {
                            $error = date('Y-m-d H:i:s') . " - Запись не внесена - Такая операция уже есть - " . $calcID . " - " . $operationData['NAME'] . " - " . $data['date'] . " - " . $data['sum'] . " - " . $data['doc'] . " - " . $data['comment'] . "\n";
                            $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!2(' . $calcID . ' - ' . $result[0]['ufOperation'] . ") \n";
                            file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                            $info['error'] = $error;
                            $info['item'] = null;
                            return $info;
                        }else{$info['item']['rewrite'] = true;}
                    }elseif (!$result[0]['ufDateFact']) {
                        $info['item'] = $result[0];
                        $info['item']['name'] = $operationData['NAME'];
                        $info['error'] = null;
                        return $info;
                    }else {
                        $error = date('Y-m-d H:i:s') . " - Запись не внесена - Свободной оперции для этого платежа нет - " . $calcID . " - " . $operationData['NAME'] . " - " . $data['date'] . " - " . $data['sum'] . " - " . $data['doc'] . " - " . $data['comment'] . "\n";
                        $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!1(' . $calcID . ' - ' . $result[0]['ufOperation'] . ") \n";
                        file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                        $info['error'] = $error;
                        $info['item'] = null;
                        return $info;

                    }
                }
                $info['item'] = $result[0];
                $info['item']['name'] = $operationData['NAME'];
          	    $info['error'] = null;
                return $info;
            }

        } catch (\Exception $e) {
            $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в поиске сделки или операции\n" . $e->getMessage();
            file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
        }

        $info['item'] = $result[0];
        $info['item']['name'] = $operationData['NAME'];
        $info['error'] = null;
        return $info;
    }


    //Открытие и обработка файла выгрузки и запись новых значений
    function syncOperations($dirCsv)
    {

        $successful = 0;
        $successMsg = '';
        $error = '';
        $result = [];
        $filename = $dirCsv;


        try {
            // Читаем построчно
            if (($handle = fopen($filename, "r")) !== false) {
                // Читаем заголовки и сразу конвертируем их из Windows-1251 в UTF-8
                $rawHeaders = fgetcsv($handle, 0, ";", "\"", "\\");
                $headers = array_map(function ($item) {
                    return mb_convert_encoding($item, "UTF-8", "Windows-1251");
                }, $rawHeaders);

                while (($row = fgetcsv($handle, 0, ";", "\"", "\\")) !== false) {
                    if (count($headers) === count($row)) {
                        // 1. Конвертируем кодировку и обрезаем лишние пробелы по краям
                        $rowUtf8 = array_map(function ($item) {
                            $item = mb_convert_encoding($item, "UTF-8", "Windows-1251");
                            return trim($item);
                        }, $row);

                        // 2. Создаем ассоциативный массив для удобного обращения к полям
                        $item = array_combine($headers, $rowUtf8);

                        // 3. Обработка суммы: меняем запятую на точку и удаляем пробелы внутри числа
                        //UPD: 9.2.26 Убрано за ненадобностью, из 1С Суммы приходят с точкой
//                        if (isset($item['sum'])) {
//                            // Заменяем запятую на точку, удаляем любые пробелы (в т.ч. неразрывные)
//                            $cleanSum = str_replace([',', ' ', "\xc2\xa0"], ['.', '', ''], $item['sum']);
//                            $item['sum'] = (float)$cleanSum;
//                        }

                        $result[] = $item;
                    }
                }
                fclose($handle);
            }
        } catch (\Exception $e) {
            $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в открытии или обработки файла выгрузки\n" . $e->getMessage();
            file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
        }

        foreach ($result as $item) {

            //Получаем операцию для дальнейшего изменения
            $b24 = getOperation($item['id'], $item['operation'], ['date' => FixDate($item['date']), 'sum' => $item['sum'], 'doc' => $item['doc'], 'comment' => $item['comment']]);

            if ($b24['error'])
            {
                $error .= $b24['error'];
            }

            if ($b24['item']) {
                try {
                    CurlBitrix24('crm.item.update', array(
                        'entityTypeId' => 1034,
                        'id' => $b24['item']['id'],
                        'fields' => ['ufPriceFact' => $item['sum'], 'ufDateFact' => FixDate($item['date']), 'ufNote' => $item['doc'].' '.$item['comment'] . ' '. $b24['item']['ufNote']]
                    ));
                    if (isset($b24['item']['rewrite']))
                    {
                        $successMsg .= date('Y-m-d H:i:s') . " - Запись перезаписана - " . $item['id'] . " - " . $b24['item']['name'] . " - " . $item['date'] . " - " . $item['sum'] . " - " . $item['doc'] . " - " . $item['comment'] . "\n";
                    }else {$successMsg .= date('Y-m-d H:i:s') . " - Запись внесена - " . $item['id'] . " - " . $b24['item']['name'] . " - " . $item['date'] . " - " . $item['sum'] . " - " . $item['doc'] . " - " . $item['comment'] . "\n";}
                    $successful++;
                } catch (\Exception $e) {
                    $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в записи данных выгрузки(" . $b24['item']['id'] . " - " . $b24['item']['ufOperation'] . ")\n" . $e->getMessage();
                    file_put_contents(realpath(__DIR__) . '\bitrix' . '\error_log.txt', $errorMessage, FILE_APPEND);
                }
            }

        }


        $logMessage = date('Y-m-d H:i:s') . " - " . count($result) . " Строк обработано - " . $successful . " Строк записано\n";
        $errorMsg = $logMessage;
        $errorMsg .= $successMsg;
        $errorMsg .= $error;
        $errorMsg = mb_convert_encoding($errorMsg, 'Windows-1251', 'UTF-8');
        file_put_contents(realpath(__DIR__) . '\bitrix' . '\sync_log.txt', $logMessage, FILE_APPEND);
        file_put_contents(realpath(__DIR__) . '\bitrix' . '\log.txt', $errorMsg, LOCK_EX);
        return $logMessage;

    };




