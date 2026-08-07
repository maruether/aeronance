<?php

declare(strict_types=1);

return [

    'rejected' => [
        'unreadable' => 'Die Datei konnte nicht gelesen werden.',
        'unknown_type' => 'Das ist keine PDF-, JPEG- oder PNG-Datei. Geprüft wird der '
            .'Inhalt, nicht der Dateiname — eine umbenannte Datei hilft also nicht weiter.',
        'truncated' => 'Die Datei beginnt wie ein :type, endet aber nicht wie eines. '
            .'Wahrscheinlich ist der Upload abgebrochen; sonst stimmt mit der Datei etwas '
            .'nicht.',
        'extension_mismatch' => 'Der Inhalt ist ein :actual, die Datei heißt aber '
            .'„…:claimed". Bitte mit der richtigen Endung erneut hochladen.',
        'too_big' => 'Die Datei ist größer als :limit MB.',
        'infected' => 'Die Virenprüfung hat angeschlagen (:signature). Die Datei wurde '
            .'nicht gespeichert.',
        'scanner_unavailable' => 'Die Virenprüfung ist eingeschaltet, aber nicht '
            .'erreichbar (:reason). Es wird nichts gespeichert, solange nicht geprüft '
            .'werden kann.',
    ],

];
