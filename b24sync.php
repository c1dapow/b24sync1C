<?php /** @noinspection ALL */

//  php test.php -m syncOperations -a C:\Users\user\Desktop\123\ Запуск скрипта из консоли пример
    // test.php - название файла, syncOperations - название метода, C:\Users\user\Desktop\123\ - аргумент метода(путь до файла C:\Users\user\Desktop\123\test.csv)

// Парсинг аргументов командной строки
$options = getopt("m:a:", ["method:", "arg:"]);

$method = $options['m'] ?? $options['method'] ?? null;
$argument = $options['a'] ?? $options['arg'] ?? null;

// Получение текущей папки скрипта
$path = realpath(__DIR__);

if ($method && $argument) {
    // Ваша логика обработки
    echo "Метод: $method\n";
    echo "Аргумент: $argument\n";

    // Вызов метода
    if (function_exists($method)) {
        $result = $method($argument);
        echo "Результат: $result\n";
    }
}

    //Подключение к б24, в аргумены передаем метод и массив данных
    function CurlBitrix24($method, $arData = array())
    {
        $queryUrl = "your-webhook" . $method;

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
            curl_close($curl);
        }catch (\Exception $e)
        {
            $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в подключению к Битрикс24\n" . $e->getMessage();
            file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
        }


        return json_decode($result, true);
    };

    // Получение операции по ID заявки на расчет и ID операции из справочника
    function getOperation($calcID, $operation, $data = array())
    {

        var_dump(realpath(__DIR__));
        die();

        try {
            // Получение сделки по ID заявки на расчет
            $dealID = CurlBitrix24('crm.deal.list.json', array(
                'select' => ['ID'],
                'filter' => ['CATEGORY_ID' => 1, 'UF_CALC_REQUEST_ID' => $calcID],
                'order' => []
            ))['result'][0]['ID'];

            // ufOperation - id из справочника операций
            if ($dealID) {
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
                file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
                return null;
            }

            if (!$result) {
                $errorMessage = date('Y-m-d H:i:s') . " - Такой операции нет!\n";
                file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);

            }
            if (count($result) > 1)
            {
                foreach ($result as $key => $item) {
                    // Проверка на заполненость полей в операции, при заполненности не даем изменять
                    if ($item['ufNote'] || $item['ufDateFact'] || $item['ufPriceFact']) {
                        $contain = str_contains($item['ufNote'], $data['doc']);
                        $sum = $numbers = preg_replace('/[^0-9.]/', '', $item['ufPriceFact']);
                        if (date('d.m.y', strtotime($item['ufDateFact'])) == $data['date'] && $contain) {
                            if ($sum == $data['sum'])
                            {
                                $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!(' . $item['id'] . ' - ' . $item['ufOperation'] . ") \n";
                                file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
                                continue;
                            }else {return $result[$key];}
                        }else {
                            $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!(' . $item['id'] . ' - ' . $item['ufOperation'] . ") \n";
                            file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
                            continue;
                        }
                    }else {return $result[$key];}
                }
            }else {
                if ($result[0]['ufNote'] || $result[0]['ufDateFact'] || $result[0]['ufPriceFact']) {
                    $contain = str_contains($result[0]['ufNote'], $data['doc']);
                    $sum = $numbers = preg_replace('/[^0-9.]/', '', $result[0]['ufPriceFact']);
                    if (date('d.m.y', strtotime($result[0]['ufDateFact'])) == $data['date'] && $contain) {
                        if ($sum == $data['sum'])
                        {
                            $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!(' . $result[0]['id'] . ' - ' . $result[0]['ufOperation'] . ") \n";
                            file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
                            return null;
                        }
                    }else {
                        $errorMessage = date('Y-m-d H:i:s') . ' - Свободной оперции для этого платежа нет!(' . $result[0]['id'] . ' - ' . $result[0]['ufOperation'] . ") \n";
                        file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
                        return null;
                    }
                }

                return $result[0];
            }

        } catch (\Exception $e) {
            $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в поиске сделки или операции\n" . $e->getMessage();
            file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
        }


        return $result[0];
    }


    //Открытие и обработка файла выгрузки и запись новых значений
    function syncOperations($dir)
    {

        $successful = 0;
        $result = [];


        try {
            $filename = $dir . 'test.csv';
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
            file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
        }

        foreach ($result as $item) {

            //Получаем операцию для дальнейшего изменения
            $b24 = getOperation($item['id'], $item['operation'], ['date' => $item['date'], 'doc' => $item['doc'], 'sum' => $item['sum']]);

            if ($b24) {
                try {
                    CurlBitrix24('crm.item.update', array(
                        'entityTypeId' => 1034,
                        'id' => $b24['id'],
                        'fields' => ['ufPriceFact' => $item['sum'], 'ufDateFact' => $item['date'], 'ufNote' => $item['doc'].' '.$item['comment']]
                    ));
                    $successful++;
                } catch (\Exception $e) {
                    $errorMessage = date('Y-m-d H:i:s') . " - Ошибка в записи данных выгрузки(" . $b24['id'] . " - " . $b24['ufOperation'] . ")\n" . $e->getMessage();
                    file_put_contents($path . 'error_log.txt', $errorMessage, FILE_APPEND);
                }
            }

        }


        $logMessage = date('Y-m-d H:i:s') . " - " . count($result) . " Строк обработано - " . $successful . " Строк записано\n";
        file_put_contents($path . 'sync_log.txt', $logMessage, FILE_APPEND);
        return $logMessage;

    };


