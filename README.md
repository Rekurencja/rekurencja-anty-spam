# Oh Honey

## Opis
Oh Honey, to narzędzie WordPress zaprojektowane do zapobiegania spamowi z formularzy CF7.

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


