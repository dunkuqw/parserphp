<?php

// Конфигурация
$categories = [
      [
        'name' => 'труба нержавеющая AISI-304',
        'url_template' => 'https://krasnoyarsk.truboproduct.ru/catalog/truba_nerzhavejuschaja/tip__perforirovannaja/tolschina-stenki__2&2-2&2-5/marka__aisi-304/page__%d/',// здесь можно выбрать, в каком формате переключать страницы, по базе (page__%d/), но бывает и (%PAGEN_1=%d)
        'pages' => 22,//здесь выбираете, сколько потребуется спарсить страниц
        'csv' => 'truba_aisi_304.csv'//название файла, который будет создаваться для хранения данных
      ],
//    [
//        'name' => 'лист стальной горячекатаный 1мм',
//        'url_template' => 'https://krasnoyarsk.truboproduct.ru/catalog/stalnoj_list/tolschina__1/metod-izgotovlenija__gorjachekatanyj/marka__st3gps&st3gsp&st3kp&st3ps&st3sp&st4ps&st4sp/gost__gost-19903-74/page__%d/',
//        'pages' => 8,
//        'csv' => 'list_stalnoj_1mm.csv'
//    ],
//    [
//        'name' => 'лист стальной горячекатаный 2мм',
//        'url_template' => 'https://krasnoyarsk.truboproduct.ru/catalog/stalnoj_list/tolschina__2/metod-izgotovlenija__gorjachekatanyj/marka__st3gps&st3gsp&st3kp&st3ps&st3sp&st4ps&st4sp/page__%d/',
//        'pages' => 13,
//        'csv' => 'list_stalnoj_2mm.csv'
//    ],
//    [
//        'name' => 'Стальные трубы ГОСТ 9941-81',
//        'url_template'=>'https://krasnoyarsk.list-prom.com/product/truba-nerzhaveyushchaya/truba-nerzhaveyushchaya-3kh1-kapillyarnaya-stal-12kh18n10t-gost-14162-79/page__%d',
//        'pages'=> 1,
//        'csv'=>'stalnietrubi_bambam.csv',
//    ]
];

// Функция загрузки HTML через cURL
function fetch_html($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0',//можно изменить количество useragent
        CURLOPT_TIMEOUT => 30,
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
        echo "Ошибка загрузки: " . curl_error($ch) . "\n";
    }
    curl_close($ch);
    return $html;
}

// Функция для извлечения текста через XPath
function xpath_text($xpath, $query, $contextNode = null) {
    $nodes = $xpath->query($query, $contextNode);
    if($nodes->length > 0) {
        return trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
    }
    return '';
}

// Парсинг страницы каталога - получение ссылок на товары
function parse_category_page($html, $base_url) {
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $links = [];
    // Для каждой категории подсказать правильный xpath ссылки товара
    // Смотрим по ссылкам с классом product-img или product-title или href с /product/
    $nodes = $xpath->query("//a[contains(@href,'/product/') or contains(@class,'product-title') or contains(@class,'product-img')]");
    foreach($nodes as $node) {
        $href = $node->getAttribute('href');
        if ($href) {
            if (strpos($href, 'http') !== 0) {
                $purl = $base_url . $href;
            } else {
                $purl = $href;
            }
            $links[$purl] = true; // уникальные ссылки
        }
    }

    return array_keys($links);
}

// Парсинг страницы товара
function parse_product_page($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $data = [];

    // Название
    $data['Название'] = xpath_text($xpath, "//h1[contains(@class,'product_title') or contains(@class,'title') or contains(@class,'name')] | //h1");

    // Описание
    $data['Описание'] = xpath_text($xpath, "//div[contains(@class,'description')] | //div[contains(@class,'desc')]");

    // Цена
    $price_str = xpath_text($xpath, "//span[contains(text(),' ₽')]");
    $data['Цена'] = trim(preg_replace('/[^\d.,]/u', '', $price_str)); // оставляем цифры и запятые

    $nodes = $xpath->query("//span[contains(@class,'in-product__desc_text_item')]");//здесь выбираются данные, если они имеют одинаковое название и по очереди он парсит их
    if ($nodes->length >= 6) {
        $data['Длина, мм'] = trim($nodes->item(0)->textContent);
        $data['Толщина, мм'] = trim($nodes->item(1)->textContent);
        $data['Ширина, мм'] = trim($nodes->item(2)->textContent);
        $data['Метод изготовления'] = trim($nodes->item(3)->textContent);
        $data['ГОСТ'] = trim($nodes->item(4)->textContent);
        $data['Марка'] = trim($nodes->item(5)->textContent);
    }

    // Характеристики: ищем в таблицах или списках, класс table или product-params и т.д.
    $rows = $xpath->query("//table[contains(@class,'params') or contains(@class,'characteristics') or contains(@class,'spec')]//tr");
    foreach($rows as $row) {
        $cells = $xpath->query(".//td", $row);
        if($cells->length >= 2) {
            $key = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
            $value = trim(preg_replace('/\s+/', ' ', $cells->item(1)->textContent));
            $data[$key] = $value;
        }
    }

    // Попытка получить характеристики из списка dt/dd
    $dl_blocks = $xpath->query("//dl[contains(@class,'params') or contains(@class,'characteristics') or contains(@class,'spec')]");
    foreach($dl_blocks as $dl) {
        $dts = $xpath->query(".//dt", $dl);
        $dds = $xpath->query(".//dd", $dl);
        $count = min($dts->length, $dds->length);
        for($i=0; $i < $count; $i++) {
            $key = trim(preg_replace('/\s+/', ' ', $dts->item($i)->textContent));
            $value = trim(preg_replace('/\s+/', ' ', $dds->item($i)->textContent));
            $data[$key] = $value;
        }
    }

    return $data;
}

function get_pagination_links($html, $base_url) {
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $links = [];
    $pageNodes = $xpath->query("//div[contains(@class, 'pagination')]//a[@href]");
    foreach ($pageNodes as $node) {
        $href = $node->getAttribute('href');
        if (strpos($href, 'http') !== 0) {
            $href = rtrim($base_url, '/') . '/' . ltrim($href, '/');
        }
        $links[$href] = true;
    }
    return array_keys($links);
}
// Сохранение массива в CSV с заголовком и BOM
function save_to_csv($filePath, $rows) {
    $fp = fopen($filePath, 'w');
    if(!$fp) {
        echo "Ошибка открытия файла $filePath для записи\n";
        return;
    }
    // BOM utf-8 для Excel
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

    // Заголовки - все ключи из всех строк
    $header = [];
    foreach($rows as $row) {
        $header = array_unique(array_merge($header, array_keys($row)));
    }
    fputcsv($fp, $header, ';');

    foreach ($rows as $row) {
        $line = [];
        foreach ($header as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($fp, $line, ';');
    }
    fclose($fp);
}

// Основной запуск
foreach ($categories as $category) {
    echo "Парсим категорию: ", $category['name'], PHP_EOL;
    $all_products = [];
    $base_url_parsed = parse_url($category['url_template']);
    $base_url = $base_url_parsed['scheme'] . '://' . $base_url_parsed['host'];

    for($page=1; $page <= $category['pages']; $page++) {
        $url = sprintf($category['url_template'], $page);
        echo " Страница $page из ", $category['pages'], PHP_EOL;

        $html = fetch_html($url);
        if(!$html) {
            echo "  Не удалось получить страницу: $url\n";
            continue;
        }

        $product_links = parse_category_page($html, $base_url);
        echo "  Найдено товаров: ", count($product_links), PHP_EOL;

        foreach($product_links as $link) {
            echo "   Парсим товар: $link\n";
            $prod_html = fetch_html($link);
            if(!$prod_html) {
                echo "    Не удалось загрузить товар $link\n";
                continue;
            }
            $product_data = parse_product_page($prod_html);
            $all_products[] = $product_data;

            // Задержка 1 сек чтобы не перегружать сервер
            sleep(1);
        }
    }

    // Сохраняем результаты в CSV
    save_to_csv($category['csv'], $all_products);
    echo "Категория '{$category['name']}' успешно сохранена в файл '{$category['csv']}'\n\n";
}

echo "Парсинг завершен\n";

?>
