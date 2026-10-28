<?php
namespace OcMcp;

/**
 * Labels of the OpenCart admin forms in the module's language when the store has no admin language pack for it.
 * The access dialog reads field names from OpenCart's own language files; a store that runs its admin in English
 * but the module in Ukrainian would show "Meta Tag Title" next to Ukrainian text. With the store's own Ukrainian
 * pack installed its labels are used and this map is not needed.
 */
final class FormLabels {
    /** English: names OpenCart has no label for, built from the column name ("Gdpr ID") made readable. */
    const EN = [
        'Account ID' => 'Account Terms',
        'Affiliate ID' => 'Affiliate Terms',
        'Checkout ID' => 'Checkout Terms',
        'Return ID' => 'Return Terms',
        'Cookie ID' => 'Cookie Policy',
        'Gdpr ID' => 'GDPR Policy',
        'Layout ID' => 'Default Layout',
        'Affiliate Group ID' => 'Affiliate Group',
        'Url' => 'URL',
        'Date Expire' => 'Expiry Date',
        'Date Next' => 'Next Date',
        'Failed Status ID' => 'Failed Status',
        'Fraud Status ID' => 'Fraud Status',
        'Void Status ID' => 'Void Status',
        'Return Status ID' => 'Return Status',
        'Subscription Status ID' => 'Subscription Status',
        'Subscription Active Status ID' => 'Subscription Active Status',
        'Subscription Canceled Status ID' => 'Subscription Canceled Status',
        'Subscription Denied Status ID' => 'Subscription Denied Status',
        'Subscription Expired Status ID' => 'Subscription Expired Status',
        'Subscription Failed Status ID' => 'Subscription Failed Status',
        'Subscription Suspended Status ID' => 'Subscription Suspended Status',
        'Invoice No' => 'Invoice No.',
        'Language ID' => 'Language',
        'Referer' => 'Referrer',
    ];

    /** One field whose English label is shared with another meaning ("Theme" of the store vs of a gift voucher). */
    const UK_FIELD = ['voucher.voucher_theme_id' => 'Тема сертифіката', 'order_voucher.voucher_theme_id' => 'Тема сертифіката', 'address.default' => 'Основна адреса'];

    const UK = [
        'Account Terms' => 'Сторінка умов реєстрації', 'Affiliate Group' => 'Група партнерів', 'Affiliate Terms' => 'Сторінка умов для партнерів', 'Alert Mail' => 'Сповіщення поштою', 'API User' => 'API-користувач', 'Checkout Terms' => 'Сторінка умов оформлення', 'Cookie Policy' => 'Сторінка політики cookie', 'Default Layout' => 'Макет за замовчуванням', 'Failed Order Status' => 'Статус невдалого замовлення', 'Fraud Order Status' => 'Статус підозри на шахрайство', 'GDPR Policy' => 'Сторінка GDPR', 'Return Terms' => 'Сторінка умов повернення', 'Subscription Active Status' => 'Статус активної підписки', 'Subscription Canceled Status' => 'Статус скасованої підписки', 'Subscription Denied Status' => 'Статус відхиленої підписки', 'Subscription Expired Status' => 'Статус завершеної підписки', 'Subscription Failed Status' => 'Статус невдалої підписки', 'Subscription Status' => 'Статус підписки', 'Subscription Suspended Status' => 'Статус призупиненої підписки', 'Void Order Status' => 'Статус анульованого замовлення',
        'Additional Alert Mail' => 'Додаткові e-mail для сповіщень', 'Address Format Name' => 'Назва формату адреси', 'Allow Forgotten Password' => 'Дозволити відновлення пароля', 'Allowed File Extensions' => 'Дозволені розширення файлів', 'Allowed File Mime Types' => 'Дозволені MIME-типи файлів', 'Cost' => 'Вартість', 'Country Name' => 'Назва країни', 'Display Errors' => 'Показувати помилки', 'Error Log Filename' => 'Файл журналу помилок', 'Mail' => 'Пошта', 'Geo Zone' => 'Геозона', 'Geo Zone Name' => 'Назва геозони', 'Hostname' => 'Сервер', 'Length Title' => 'Назва одиниці довжини', 'Length Unit' => 'Позначення довжини', 'Log Errors' => 'Записувати помилки в журнал', 'Mail Engine' => 'Спосіб надсилання пошти', 'Mail Parameters' => 'Параметри пошти', 'Max File Size' => 'Макс. розмір файлу', 'Password Minimum Length' => 'Мін. довжина пароля', 'Port' => 'Порт', 'Return Action Name' => 'Назва дії з поверненням', 'Return Reason Name' => 'Назва причини повернення', 'Return Status Name' => 'Назва статусу повернення', 'Robots' => 'Роботи (боти)', 'Shipping Estimator' => 'Розрахунок доставки', 'SMTP Hostname' => 'SMTP-сервер', 'SMTP Port' => 'SMTP-порт', 'SMTP Timeout' => 'Тайм-аут SMTP', 'Tax Class Title' => 'Назва податкового класу', 'Tax Name' => 'Назва податку', 'Theme Directory' => 'Папка теми', 'Timeout' => 'Тайм-аут', 'Use Shared Sessions' => 'Спільні сесії', 'Weight Title' => 'Назва одиниці ваги', 'Zone' => 'Регіон', 'Zone Code' => 'Код регіону', 'Zone Name' => 'Назва регіону',
        'Account ID' => 'Сторінка умов реєстрації', 'Address Format' => 'Формат адреси', 'Affiliate Group ID' => 'Група партнерів', 'Affiliate ID' => 'Сторінка умов для партнерів', 'API Key' => 'API-ключ', 'API Username' => 'Імʼя API-користувача', 'Balance' => 'Баланс', 'Based On' => 'На основі', 'Call' => 'Викликів', 'Captcha Page' => 'Сторінки з капчею', 'Cart' => 'Кошик', 'Checkout ID' => 'Сторінка умов оформлення', 'Clicks' => 'Переходи', 'Complete Order Status' => 'Статус виконаного замовлення', 'Cookie ID' => 'Сторінка політики cookie', 'Currency Title' => 'Назва валюти', 'Customer Groups' => 'Групи покупців', 'Date Expire' => 'Діє до', 'Date Next' => 'Наступна дата', 'Decimal Places' => 'Знаків після коми', 'Download Name' => 'Назва завантаження', 'E-mail PayPal' => 'E-mail PayPal', 'Failed Status ID' => 'Статус невдалого платежу', 'Fraud Status ID' => 'Статус підозри на шахрайство', 'Gdpr ID' => 'Сторінка GDPR', 'Image Additional Height' => 'Додаткові фото: висота', 'Image Additional Width' => 'Додаткові фото: ширина', 'Image Article Height' => 'Фото статті: висота', 'Image Article Width' => 'Фото статті: ширина', 'Image Cart Height' => 'Фото в кошику: висота', 'Image Cart Width' => 'Фото в кошику: ширина', 'Image Category Height' => 'Фото категорії: висота', 'Image Category Width' => 'Фото категорії: ширина', 'Image Compare Height' => 'Фото в порівнянні: висота', 'Image Compare Width' => 'Фото в порівнянні: ширина', 'Image Default Height' => 'Фото за замовчуванням: висота', 'Image Default Width' => 'Фото за замовчуванням: ширина', 'Image Location Height' => 'Фото магазину: висота', 'Image Location Width' => 'Фото магазину: ширина', 'Image Popup Height' => 'Збільшене фото: висота', 'Image Popup Width' => 'Збільшене фото: ширина', 'Image Product Height' => 'Фото в списку товарів: висота', 'Image Product Width' => 'Фото в списку товарів: ширина', 'Image Related Height' => 'Фото супутніх товарів: висота', 'Image Related Width' => 'Фото супутніх товарів: ширина', 'Image Thumb Height' => 'Головне фото товару: висота', 'Image Thumb Width' => 'Головне фото товару: ширина', 'Image Topic Height' => 'Фото теми блогу: висота', 'Image Topic Width' => 'Фото теми блогу: ширина', 'Image Wishlist Height' => 'Фото в закладках: висота', 'Image Wishlist Width' => 'Фото в закладках: ширина', 'ISO Code (2)' => 'ISO-код (2)', 'ISO Code (3)' => 'ISO-код (3)', 'Language Catalog' => 'Мова вітрини', 'Layout ID' => 'Макет за замовчуванням', 'Level' => 'Рівень', 'Mask' => 'Імʼя файлу для покупця', 'Permission' => 'Права доступу', 'Postcode Required' => 'Індекс обовʼязковий', 'Processing Order Status' => 'Статус замовлення в обробці', 'Products' => 'Товари', 'Referer' => 'Звідки прийшов', 'Remaining' => 'Залишилось', 'Return ID' => 'Сторінка умов повернення', 'Return Status ID' => 'Статус повернення за замовчуванням', 'Review Status' => 'Статус відгуку', 'Salt' => 'Сіль', 'Stock Status' => 'Статус наявності', 'Sub Category' => 'Підкатегорії', 'Subscription Active Status ID' => 'Статус активної підписки', 'Subscription Canceled Status ID' => 'Статус скасованої підписки', 'Subscription Denied Status ID' => 'Статус відхиленої підписки', 'Subscription Expired Status ID' => 'Статус завершеної підписки', 'Subscription Failed Status ID' => 'Статус невдалої підписки', 'Subscription Status ID' => 'Статус підписки за замовчуванням', 'Subscription Suspended Status ID' => 'Статус призупиненої підписки', 'Symbol Left' => 'Символ зліва', 'Symbol Right' => 'Символ справа', 'Tax Rate' => 'Податкова ставка', 'Token' => 'Токен', 'Total Accounts' => 'Кількість облікових записів', 'Trial Remaining' => 'Залишок пробного періоду', 'Url' => 'URL', 'Username' => 'Імʼя користувача', 'Void Status ID' => 'Статус анульованого платежу', 'Voucher Theme Description' => 'Опис теми сертифіката', 'Weight Unit' => 'Одиниця ваги', 'Wishlist' => 'Закладки', 'Use SEO URLs' => 'Використовувати SEO URL',
        'Order Status' => 'Статус замовлення', 'Date Modified' => 'Дата зміни', 'Addresses' => 'Адреси', 'Address' => 'Адреса', 'Default' => 'За замовчуванням', 'Affiliates' => 'Партнери', 'Affiliate' => 'Партнер',
        'Password' => 'Пароль', 'Gift Vouchers' => 'Подарункові сертифікати', 'Voucher Themes' => 'Теми сертифікатів', 'Customers' => 'Покупці', 'Language ID' => 'Мова',
        'ABA/BSB number (Branch Number)' => 'Номер ABA/BSB (відділення)', 'Accept Language' => 'Мова браузера', 'Account Name' => 'Власник рахунку',
        'Account Number' => 'Номер банківського рахунку', 'Action' => 'Дія', 'Additional Product Image Size (W x H)' => 'Розмір додаткових фото товару (Ш x В)',
        'Address' => 'Адреса', 'Address 1' => 'Адреса 1', 'Address 2' => 'Адреса 2', 'Administration Language' => 'Мова адмінки',
        'Affiliate Commission (%)' => 'Комісія партнера (%)', 'Affiliate Expire' => 'Термін дії партнерського посилання', 'Affiliate Requires Approval' => 'Партнери потребують схвалення',
        'Affiliate Status' => 'Статус партнера', 'Allow Comments' => 'Дозволити коментарі', 'Allow Guest Reviews' => 'Відгуки від гостей',
        'Allow Reviews' => 'Дозволити відгуки', 'Allow Reviews Of products purchased' => 'Відгуки лише на куплені товари', 'Amount' => 'Сума',
        'Approve New Customers' => 'Схвалювати нових покупців', 'Article Image Size (W x H)' => 'Розмір фото статті (Ш x В)', 'Article Name' => 'Назва статті',
        'Articles' => 'Статті', 'Attribute' => 'Атрибут', 'Attribute Group Name' => 'Назва групи атрибутів', 'Attribute Name' => 'Назва атрибута',
        'Audience' => 'Аудиторія', 'Author' => 'Автор', 'Auto Approve Comments' => 'Автосхвалення коментарів', 'Auto Update Currency' => 'Автооновлення курсів валют',
        'Automatic Commission' => 'Автоматична комісія', 'Bank Name' => 'Назва банку', 'Billing Address required' => 'Обовʼязкова платіжна адреса',
        'Bottom' => 'У нижньому меню (футер)', 'Broadcast' => 'Розсилка', 'Campaign Description' => 'Опис кампанії', 'Campaign Name' => 'Назва кампанії',
        'Captcha' => 'Капча', 'Cart Image Size (W x H)' => 'Розмір фото в кошику (Ш x В)', 'Categories' => 'Категорії', 'Category' => 'Категорія',
        'Category Image Size (W x H)' => 'Розмір фото категорії (Ш x В)', 'Category Name' => 'Назва категорії', 'Category Product Count' => 'Кількість товарів у категоріях',
        'Cheque Payee Name' => 'Отримувач чека', 'City' => 'Місто', 'Client' => 'Клієнт', 'Code' => 'Код', 'Columns' => 'Колонки', 'Comment' => 'Коментар',
        'Commenter' => 'Автор коментаря', 'Comments Interval' => 'Інтервал між коментарями', 'Commission' => 'Комісія', 'Commission (%)' => 'Комісія (%)',
        'Company' => 'Компанія', 'Compare Image Size (W x H)' => 'Розмір фото в порівнянні (Ш x В)',
        'Config image additional height' => 'Висота додаткових фото', 'Config image article height' => 'Висота фото статті', 'Config image cart height' => 'Висота фото в кошику',
        'Config image category height' => 'Висота фото категорії', 'Config image compare height' => 'Висота фото в порівнянні', 'Config image default height' => 'Висота фото за замовчуванням',
        'Config image location height' => 'Висота фото магазину', 'Config image popup height' => 'Висота збільшеного фото', 'Config image product height' => 'Висота фото товару',
        'Config image related height' => 'Висота фото супутніх товарів', 'Config image thumb height' => 'Висота мініатюри', 'Config image topic height' => 'Висота фото теми',
        'Config image wishlist height' => 'Висота фото в закладках', 'Country' => 'Країна', 'Coupon Name' => 'Назва купона', 'Currency' => 'Валюта',
        'Currency Rate Engine' => 'Джерело курсів валют', 'Currency code' => 'Код валюти', 'Currency value' => 'Курс валюти', 'Custom Field Name' => 'Назва додаткового поля',
        'Custom field' => 'Додаткове поле', 'Customer Group' => 'Група покупців', 'Customer Group Name' => 'Назва групи покупців', 'Customer Login' => 'Вхід покупця',
        'Customers Activity' => 'Активність покупців', 'Customers Online' => 'Покупці онлайн', 'Customers Online Timeout' => 'Тайм-аут «онлайн»', 'Cycle' => 'Цикл',
        'Data' => 'Дані', 'Date Added' => 'Дата додавання', 'Date Available' => 'Дата надходження', 'Date End' => 'Дата завершення', 'Date Start' => 'Дата початку',
        'Date ordered' => 'Дата замовлення', 'Default Autocomplete Limit' => 'Ліміт автодоповнення', 'Default Image Size (W x H)' => 'Розмір фото за замовчуванням (Ш x В)',
        'Default Items Per Page' => 'Товарів на сторінці', 'Default Items Per Page (Admin)' => 'Записів на сторінці (адмінка)', 'Description' => 'Опис', 'Directory' => 'Папка',
        'Discount' => 'Знижка', 'Display Prices With Tax' => 'Показувати ціни з податком', 'Display Stock' => 'Показувати залишок', 'Display Weight on Cart Page' => 'Показувати вагу в кошику',
        'Downloads' => 'Файли для завантаження', 'Duration' => 'Тривалість', 'E-Mail' => 'E-mail', 'EAN' => 'EAN', 'Expires' => 'Діє до', 'Extension' => 'Розширення',
        'Failed' => 'Невдалі', 'Fax' => 'Факс', 'Filter Group Name' => 'Назва групи фільтрів', 'Filter Name' => 'Назва фільтра', 'Filters' => 'Фільтри',
        'First Name' => 'Імʼя', 'Forwarded IP' => 'IP через проксі', 'Free Shipping' => 'Безкоштовна доставка', 'Frequency' => 'Періодичність', 'From E-Mail' => 'E-mail відправника',
        'From Name' => 'Імʼя відправника', 'GDPR Limit' => 'Строк зберігання за GDPR', 'General' => 'Основне', 'Geocode' => 'Геокод', 'Guest Checkout' => 'Замовлення без реєстрації',
        'Height' => 'Висота', 'History' => 'Історія', 'Html' => 'HTML', 'IP Address' => 'IP-адреса', 'ISBN' => 'ISBN', 'Icon' => 'Іконка', 'Image' => 'Фото',
        'Information Title' => 'Заголовок сторінки', 'Invoice No.' => 'Номер рахунку-фактури', 'Invoice Prefix' => 'Префікс рахунку', 'Invoice no' => 'Номер рахунку-фактури',
        'Invoice prefix' => 'Префікс рахунку', 'Ip' => 'IP', 'JAN' => 'JAN', 'Key' => 'Ключ', 'Keyword' => 'SEO-адреса', 'Language' => 'Мова', 'Language Name' => 'Назва мови',
        'Language code' => 'Код мови', 'Last Name' => 'Прізвище', 'Last error' => 'Остання помилка', 'Length' => 'Довжина', 'Length Class' => 'Одиниця довжини',
        'Links' => 'Звʼязки', 'List Description Limit' => 'Довжина опису в списку', 'Local' => 'Локальне', 'Locale' => 'Локаль', 'Location' => 'Місцезнаходження',
        'Log Customer Searches' => 'Записувати пошук покупців', 'Login Display Prices' => 'Ціни лише після входу', 'MPN' => 'MPN', 'Maintenance Mode' => 'Режим обслуговування',
        'Manufacturer' => 'Виробник', 'Manufacturer Name' => 'Назва виробника', 'Max Login Attempts' => 'Макс. спроб входу', 'Message' => 'Повідомлення',
        'Meta Tag Description' => 'Мета-опис', 'Meta Tag Keywords' => 'Мета-ключові слова', 'Meta Tag Title' => 'Мета-заголовок', 'Meta Title' => 'Мета-заголовок',
        'Minimum Quantity' => 'Мінімальна кількість', 'Model' => 'Модель', 'Name' => 'Назва', 'Newsletter' => 'Розсилка новин', 'Notify Customer' => 'Повідомити покупця',
        'Opened' => 'Відкрито', 'Opening Times' => 'Години роботи', 'Option' => 'Опція', 'Option Name' => 'Назва опції', 'Option Value' => 'Значення опції',
        'Order Date' => 'Дата замовлення', 'Out Of Stock Status' => 'Статус, коли немає в наявності', 'Output Compression Level' => 'Рівень стиснення сторінок', 'Parent' => 'Батьківська категорія',
        'PayPal Email Account' => 'E-mail PayPal', 'Payment Address Custom Fields' => 'Додаткові поля платіжної адреси', 'Payment Method' => 'Спосіб оплати', 'Payment Reference' => 'Призначення платежу',
        'Payment address 1' => 'Платіжна адреса 1', 'Payment address 2' => 'Платіжна адреса 2', 'Payment address format' => 'Формат платіжної адреси', 'Payment city' => 'Місто (оплата)',
        'Payment code' => 'Код способу оплати', 'Payment company' => 'Компанія (оплата)', 'Payment country' => 'Країна (оплата)', 'Payment custom field' => 'Додаткове поле (оплата)',
        'Payment firstname' => 'Імʼя (оплата)', 'Payment lastname' => 'Прізвище (оплата)', 'Payment postcode' => 'Індекс (оплата)', 'Payment zone' => 'Регіон (оплата)',
        'Points' => 'Бали', 'Points prefix' => 'Знак балів', 'Postcode' => 'Індекс', 'Price' => 'Ціна', 'Price prefix' => 'Знак ціни', 'Priority' => 'Пріоритет',
        'Product' => 'Товар', 'Product Codes' => 'Коди товару', 'Product Filters' => 'Фільтри товару', 'Product Image List Size (W x H)' => 'Розмір фото в списку (Ш x В)',
        'Product Image Popup Size (W x H)' => 'Розмір збільшеного фото (Ш x В)', 'Product Image Thumb Size (W x H)' => 'Розмір мініатюри товару (Ш x В)', 'Product Name' => 'Назва товару',
        'Product Report Status' => 'Звіт переглядів товарів', 'Product Returns' => 'Повернення товарів', 'Product Search' => 'Пошук товарів', 'Product Search (Admin)' => 'Пошук товарів (адмінка)',
        'Product Tags' => 'Теги товару', 'Product name' => 'Назва товару', 'Product quantity' => 'Кількість товару', 'Quantity' => 'Кількість', 'Query' => 'Запит', 'Rating' => 'Оцінка',
        'Recipients' => 'Отримувачі', 'Recurring' => 'Періодичні платежі', 'Recurring Profile' => 'Періодичний профіль', 'Recurring cycle' => 'Цикл платежів', 'Recurring duration' => 'Кількість платежів',
        'Recurring frequency' => 'Періодичність платежів', 'Recurring price' => 'Сума платежу', 'Region / State' => 'Регіон / область', 'Related Product Image Size (W x H)' => 'Розмір фото супутніх товарів (Ш x В)',
        'Related Products' => 'Супутні товари', 'Required' => 'Обовʼязково', 'Requires Shipping' => 'Потрібна доставка', 'Return Action' => 'Дія з поверненням', 'Return Reason' => 'Причина повернення',
        'Return Status' => 'Статус повернення', 'Reward' => 'Бонусні бали', 'Reward Points' => 'Бонусні бали', 'SKU' => 'Артикул (SKU)', 'SSL URL' => 'SSL-адреса', 'SWIFT Code' => 'SWIFT-код',
        'Safe' => 'Безпечний', 'Sent' => 'Надіслано', 'Server' => 'Сервер', 'Session Lifetime' => 'Тривалість сесії', 'Session Samesite Cookie' => 'SameSite для cookie сесії',
        'Shipping Address Custom Fields' => 'Додаткові поля адреси доставки', 'Shipping Method' => 'Спосіб доставки', 'Shipping address 1' => 'Адреса доставки 1', 'Shipping address 2' => 'Адреса доставки 2',
        'Shipping address format' => 'Формат адреси доставки', 'Shipping city' => 'Місто (доставка)', 'Shipping code' => 'Код способу доставки', 'Shipping company' => 'Компанія (доставка)',
        'Shipping country' => 'Країна (доставка)', 'Shipping custom field' => 'Додаткове поле (доставка)', 'Shipping firstname' => 'Імʼя (доставка)', 'Shipping lastname' => 'Прізвище (доставка)',
        'Shipping postcode' => 'Індекс (доставка)', 'Shipping zone' => 'Регіон (доставка)', 'Show Out Of Stock Warning' => 'Попереджати, коли немає в наявності', 'Sort Order' => 'Порядок сортування',
        'Special' => 'Акція', 'Status' => 'Статус', 'Stock Checkout' => 'Замовлення товарів, яких немає в наявності', 'Store' => 'Магазин', 'Store Image Size (W x H)' => 'Розмір фото магазину (Ш x В)',
        'Store Logo' => 'Логотип магазину', 'Store Name' => 'Назва магазину', 'Store Owner' => 'Власник магазину', 'Store URL' => 'Адреса магазину', 'Store name' => 'Назва магазину',
        'Store url' => 'Адреса магазину', 'Stores' => 'Магазини', 'Subject' => 'Тема', 'Subscription' => 'Підписка', 'Subscription Plan' => 'План підписки', 'Subscription Plan Name' => 'Назва плану підписки',
        'Subscription Plans' => 'Плани підписки', 'Subtract Stock' => 'Списувати зі складу', 'Tags' => 'Теги', 'Tax' => 'Податок', 'Tax Class' => 'Клас податку', 'Tax ID' => 'Податковий номер',
        'Telephone' => 'Телефон', 'Telephone Display' => 'Показувати телефон', 'Telephone Required' => 'Телефон обовʼязковий', 'Text' => 'Текст', 'Theme' => 'Тема оформлення',
        'Time Zone' => 'Часовий пояс', 'Title' => 'Заголовок', 'To E-Mail' => 'E-mail отримувача', 'To Name' => 'Імʼя отримувача', 'Token Expire' => 'Термін дії токена', 'Top' => 'У верхньому меню',
        'Topic' => 'Тема', 'Topic Image Size (W x H)' => 'Розмір фото теми (Ш x В)', 'Topic Name' => 'Назва теми', 'Topics' => 'Теми блогу', 'Total' => 'Підсумок', 'Total Amount' => 'Загальна сума',
        'Tracking' => 'Відстеження', 'Tracking Code' => 'Код відстеження', 'Tracking number' => 'Номер відстеження', 'Transactions' => 'Транзакції', 'Trial' => 'Пробний період',
        'Trial Cycle' => 'Цикл пробного періоду', 'Trial Duration' => 'Тривалість пробного періоду', 'Trial Frequency' => 'Періодичність пробного періоду', 'Trial Price' => 'Ціна пробного періоду',
        'Trial Status' => 'Пробний період увімкнено', 'Trial cycle' => 'Цикл пробного періоду', 'Trial duration' => 'Тривалість пробного періоду', 'Trial frequency' => 'Періодичність пробного періоду',
        'Trial price' => 'Ціна пробного періоду', 'Trial status' => 'Пробний період увімкнено', 'Trial tax' => 'Податок пробного періоду', 'Two-factor Security' => 'Двофакторний захист',
        'Type' => 'Тип', 'UPC' => 'UPC', 'Unit Price' => 'Ціна за одиницю', 'Use Customer Tax Address' => 'Податок за адресою покупця', 'Use SSL' => 'Використовувати SSL',
        'Use Store Tax Address' => 'Податок за адресою магазину', 'Use shipping address for billing address' => 'Адреса доставки як платіжна', 'User Agent' => 'Браузер (User-Agent)',
        'Uses Per Coupon' => 'Використань купона', 'Uses Per Customer' => 'Використань на покупця', 'Validation' => 'Перевірка', 'Value' => 'Значення', 'Voucher Max' => 'Макс. сума сертифіката',
        'Voucher Min' => 'Мін. сума сертифіката', 'Voucher Theme Name' => 'Назва теми сертифіката', 'Web Site' => 'Сайт', 'Weight' => 'Вага', 'Weight Class' => 'Одиниця ваги',
        'Weight prefix' => 'Знак ваги', 'Width' => 'Ширина', 'Wish List Image Size (W x H)' => 'Розмір фото в закладках (Ш x В)',
        // headings of a part's second form and of extensions (the Ukrainian packs leave them in English)
        'Filter Groups' => 'Групи фільтрів', 'Attribute Groups' => 'Групи атрибутів', 'Return Actions' => 'Дії з поверненням', 'Return Reasons' => 'Причини повернення', 'Tax Rates' => 'Податкові ставки',
        'Cash On Delivery' => 'Оплата при отриманні', 'Free Checkout' => 'Безкоштовне оформлення', 'Flat Rate' => 'Фіксована ставка', 'Default Store Theme' => 'Стандартна тема магазину',
        'Coupon' => 'Купон', 'Store Credit' => 'Кредит магазину', 'Shipping' => 'Доставка', 'Sub-Total' => 'Проміжний підсумок', 'Taxes' => 'Податки', 'Gift Voucher' => 'Подарунковий сертифікат',
        'Stock Status Name' => 'Назва статусу наявності', 'Order Status Name' => 'Назва статусу замовлення', 'Subscription Status Name' => 'Назва статусу підписки',
        'User Password Length' => 'Мінімальна довжина пароля користувача', 'Shipping Courier Code' => 'Код служби доставки', 'Shipping Courier Name' => 'Назва служби доставки',
    ];

    /** Translate the labels of the access map (group titles part by part, field labels) for the module's language. */
    public static function translate(array $sections, string $lang): array {
        $map = $lang === 'uk' ? self::UK : self::EN;

        if (!$map) {
            return $sections;
        }

        $low = array_change_key_case($map, CASE_LOWER); // "Custom Field" and "Custom field" are one label
        $t = function ($v) use ($map, $low) {
            return !is_string($v) ? $v : ($map[$v] ?? $low[strtolower($v)] ?? $v);
        };

        foreach ($sections as $si => $sec) {
            foreach ($sec['subs'] as $bi => $sub) {
                foreach (isset($sub['groups']) && is_array($sub['groups']) ? $sub['groups'] : [] as $gi => $g) {
                    $sections[$si]['subs'][$bi]['groups'][$gi]['title'] = implode(' › ', array_map($t, explode(' › ', (string)$g['title'])));

                    foreach ($g['fields'] as $fi => $f) {
                        $sections[$si]['subs'][$bi]['groups'][$gi]['fields'][$fi]['label'] = $lang === 'uk' && isset(self::UK_FIELD[($f['table'] ?? '') . '.' . ($f['column'] ?? '')]) ? self::UK_FIELD[$f['table'] . '.' . $f['column']] : $t($f['label'] ?? '');
                    }
                }

                foreach (isset($sub['tables']) && is_array($sub['tables']) ? $sub['tables'] : [] as $ti => $tb) {
                    foreach (isset($tb['fields']) && is_array($tb['fields']) ? $tb['fields'] : [] as $fi => $f) {
                        $sections[$si]['subs'][$bi]['tables'][$ti]['fields'][$fi]['label'] = $t($f['label'] ?? '');
                    }
                }
            }
        }

        return $sections;
    }
}
