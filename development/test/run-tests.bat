@echo off
REM Скрипт запуска PHPUnit тестов для плагина Cashback
REM Использование: run-tests.bat [группа] [файл]
REM Примеры:
REM   run-tests.bat                          -- все тесты
REM   run-tests.bat encryption               -- только тесты шифрования
REM   run-tests.bat calculation              -- только расчёты кэшбэка
REM   run-tests.bat integrity                -- только целостность данных
REM   run-tests.bat fraud                    -- только антифрод
REM   run-tests.bat reference_id             -- только генерация Reference ID

SET PHP=f:\wamp64\bin\php\php8.3.28\php.exe
SET PHPUNIT=vendor\bin\phpunit
SET CONFIG=phpunit.xml.dist

IF NOT EXIST vendor (
    echo Устанавливаем зависимости...
    %PHP% C:\ProgramData\ComposerSetup\bin\composer.phar install
)

IF "%1"=="" (
    %PHP% %PHPUNIT% --configuration %CONFIG% --testdox
) ELSE (
    %PHP% %PHPUNIT% --configuration %CONFIG% --group %1 --testdox
)
