# cf7-anti-spam-tool

## Opis
 to narzędzie WordPress zaprojektowane do zapobiegania spamowi z formularzy CF7.

## Wymagania
WordPress 5.0 lub nowszy
PHP 7.2 lub nowszy

## Instalacja
Aby wgrać na nasze strony z formularzami narzędzie anty-spamowe należy:
Klonujemy repozytorium, wypakowujemy i następnie
1. Wchodzimy w folder z naszym motywem (theme-folder)
2. Wybieramy folder w którym trzymamy narzędzia, w moim wypadku:
```
wp-content/themes/{nazwa}/src/lib/packages
```
3. Kopiujemy nasz folder do packages, następnie dodajemy w functions.php:
```
require_once 'lib/packages/oh-honey/init.php';
```

## I jazdunia, boty do pieca.

A.... jak używać? 
## Funkcjonalności:
- Limit słów pisanych capslockiem (więcej niż 3 = SPAM)
- Czarna lista słów w pliku JSON
- Cross-Site Request Forgery (CSRF) Token 
- Honey pots
- User Agent

## Pomysły:
- Blokada ruchu z określonego adresu IP lub kraju.
- Captcha / RECaptcha
- Panel admina (CPT)
- Generowanie logów wewnątrz panelu admina
- Dodawanie słów przez panel admina

![Oh Honey WordPress Tool](https://i.ibb.co/946qW0Y/d35dba84-039f-4512-8a91-6e109ebc26fa-1.webp)
