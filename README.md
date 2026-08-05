# MRIM WebSocket Gateway

<img width="938" height="648" alt="Снимок экрана — 2026-08-05 в 19 15 59" src="https://github.com/user-attachments/assets/c08ddc28-78c4-4543-a30f-1bdfc05d6a89" />




Open-source проект для работы с протоколом **MRIM (Mail.Ru Instant Messenger)** через современный WebSocket-интерфейс.

Проект представляет собой промежуточный сервер между клиентами WebSocket и оригинальным MRIM-протоколом. Он позволяет подключать современные веб-приложения к старой инфраструктуре MRIM, обеспечивая обмен сообщениями, авторизацию и работу с контактами.

# Дисклеймер

Вайбкоддинг. Я в PHP не шарю, так что не пугайтесь

## Возможности

* Подключение к MRIM-серверам через TCP
* WebSocket API для веб-клиентов
* Авторизация пользователей
* Обработка входящих и исходящих сообщений
* Работа с контакт-листом
* Поддержка MRIM packet protocol
* Кодирование и декодирование строк UTF-16LE (LPS формат)
* Будильник

## Архитектура

```
Web Client
    |
    | WebSocket
    |
    v
MRIM WebSocket Server (PHP)
    |
    | TCP / MRIM Protocol
    |
    v
MRIM Server
```

## Требования

* PHP 8.2+
* CLI режим PHP
* Расширение mbstring
* VPS или сервер с поддержкой фоновых процессов

## Запуск

### Запуск через PHP CLI
```bash
php server/websocket-server.php
```
