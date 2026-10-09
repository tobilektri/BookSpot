import { ref, computed } from 'vue'

const de = {
  app: { name: 'SchmökerSpot', tagline: 'Deine Bücher. Deine Regale. Deine Statistik.' },
  nav: { home: 'Start', books: 'Bücher', scan: 'Scannen', shelves: 'Regale', stats: 'Statistik', settings: 'Einstellungen' },
  common: {
    save: 'Speichern', cancel: 'Abbrechen', delete: 'Löschen', edit: 'Bearbeiten', back: 'Zurück', close: 'Schließen',
    add: 'Hinzufügen', search: 'Suchen', loading: 'Lädt …', none: 'Keine', yes: 'Ja', no: 'Nein', more: 'Mehr anzeigen',
    all: 'Alle', retry: 'Erneut versuchen', optional: 'optional', books: '{n} Bücher', book: '1 Buch', pages: '{n} Seiten',
    offline: 'Offline – Änderungen werden später synchronisiert', pending: '{n} Änderung(en) warten auf Sync',
    syncing: 'Synchronisiere …', confirmDelete: 'Wirklich löschen?', saved: 'Gespeichert', unknownAuthor: 'Unbekannter Autor',
    today: 'heute', update: 'Neue Version verfügbar', reload: 'Neu laden', select: 'Bitte wählen', moveTo: 'Verschieben nach …',
  },
  auth: {
    login: 'Anmelden', register: 'Registrieren', logout: 'Abmelden', username: 'Benutzername', email: 'E-Mail',
    password: 'Passwort', loginField: 'Benutzername oder E-Mail', setupTitle: 'Willkommen! Lege das erste Konto an.',
    setupHint: 'Das erste Konto wird Admin. Weitere Konten legst du danach unter Einstellungen → Benutzerverwaltung an.',
    noAccount: 'Noch kein Konto?', haveAccount: 'Schon registriert?', passwordHint: 'Mindestens 8 Zeichen',
  },
  dashboard: {
    hello: 'Hallo {name}!', searchPlaceholder: 'Titel, Autor oder ISBN suchen …', dueTitle: 'Anstehende Rückgaben',
    dueEmpty: 'Keine offenen Ausleihen.', readingTitle: 'Am Lesen', readingEmpty: 'Gerade liest du nichts. Zeit für ein neues Buch?',
    lentTitle: 'Verliehen', quickScan: 'Buch scannen', quickAdd: 'Manuell anlegen', quickImport: 'CSV importieren',
    unplaced: '{n} Bücher haben noch keinen Platz im Regal.', recent: 'Zuletzt hinzugefügt',
    enrich: 'Für {n} Bücher fehlen Cover/Beschreibungen.', enrichAction: 'Jetzt ergänzen', enrichRunning: 'Ergänze Metadaten … noch {n}',
    emptyTitle: 'Noch keine Bücher', emptyText: 'Scanne dein erstes Buch oder importiere deine Bookstats-Sammlung.',
    progressSave: 'Fortschritt speichern', statBooks: 'Bücher', statRead: 'Gelesen', statPages: 'Seiten gelesen',
  },
  due: {
    overdue: '{n} Tag(e) überfällig', today: 'Heute fällig', tomorrow: 'Morgen fällig', inDays: 'In {n} Tagen fällig', noDate: 'Ohne Frist',
    returned: 'Zurückgegeben', markReturned: 'Als zurückgegeben markieren', extend: 'Verlängern',
  },
  books: {
    title: 'Bücher', count: '{n} Bücher', empty: 'Keine Bücher gefunden.', sort: 'Sortierung', genre: 'Genre', allGenres: 'Alle Genres',
    sortTitle: 'Titel', sortAuthors: 'Autor', sortRecent: 'Neueste', sortPages: 'Seitenzahl', sortYear: 'Erscheinungsjahr', sortDue: 'Fälligkeit',
    filterUnplaced: 'Ohne Standort', filterLent: 'Verliehen', filterLibrary: 'Ausleihen', offlineList: 'Offline-Kopie vom {date}',
    selectMode: 'Auswählen', selected: '{n} ausgewählt', moveSelected: 'Auswahl verschieben',
  },
  status: { unread: 'Ungelesen', reading: 'Am Lesen', read: 'Gelesen', abandoned: 'Abgebrochen' },
  entry: {
    owned: 'Eigenes Buch', library_loan: 'Bibliotheks-Ausleihe', personal_loan: 'Privat geliehen',
    ownedHint: 'Gekauft oder geschenkt – mit Platz im Regal', libraryHint: 'Aus der Bücherei, mit Rückgabefrist', personalHint: 'Von Freunden/Familie geliehen',
  },
  book: {
    isbn: 'ISBN', title: 'Titel', authors: 'Autor(en)', publisher: 'Verlag', year: 'Erscheinungsjahr', pages: 'Seiten', genre: 'Genre',
    description: 'Beschreibung', cover: 'Cover-URL', format: 'Buchart', price: 'Preis', acquired: 'Erhalten am', language: 'Sprache',
    type: 'Art', location: 'Standort', noLocation: 'Kein Standort', setLocation: 'Standort festlegen', loanFrom: 'Ausgeliehen bei',
    library: 'Bibliothek', person: 'Person', loanStart: 'Ausgeliehen am', loanDue: 'Rückgabe bis', borrower: 'Ausgeliehen von (Nutzer)',
    lentTo: 'Verliehen an', lentAt: 'Verliehen am', lend: 'Verleihen', lendTo: 'An wen?', gotBack: 'Zurückbekommen',
    status: 'Katalogstatus', statusActive: 'Vorhanden', statusReturned: 'Zurückgegeben', statusDiscarded: 'Aussortiert',
    newTitle: 'Buch anlegen', editTitle: 'Buch bearbeiten', fetch: 'Daten abrufen', fetching: 'Suche bei Open Library, DNB und Google Books …',
    fetched: 'Daten übernommen ({source}).', notFoundOnline: 'Keine Online-Daten gefunden – bitte manuell ausfüllen.', lookupUnavailable: 'Die Buchdatenbanken sind gerade nicht erreichbar – bitte später erneut versuchen oder manuell ausfüllen.',
    exists: 'Dieses Buch ist bereits im Katalog.', openExisting: 'Vorhandenes öffnen', created: 'Buch angelegt', deleted: 'Buch gelöscht',
    personal: 'Mein Lesestand', rating: 'Bewertung', pagesRead: 'Gelesene Seiten', started: 'Begonnen', finished: 'Beendet', notes: 'Notizen',
    otherReaders: 'Andere Leser', details: 'Details', addedBy: 'Hinzugefügt von {name}', initialStatus: 'Lesestatus',
    deleteConfirm: '„{title}“ wirklich aus dem gemeinsamen Katalog löschen? Die Lesedaten aller Nutzer gehen verloren.',
    progress: '{read} von {total} Seiten', markRead: 'Fertig gelesen', startReading: 'Lesen beginnen', catalog: 'Katalog',
  },
  scan: {
    title: 'Barcode scannen', hint: 'Halte den EAN-/ISBN-Barcode auf der Buchrückseite in den Rahmen.', start: 'Kamera starten',
    stop: 'Kamera stoppen', manual: 'ISBN manuell eingeben', manualPlaceholder: 'z. B. 978-3-404-13987-3', go: 'Suchen',
    noCamera: 'Keine Kamera verfügbar oder Zugriff verweigert. Du kannst die ISBN auch eintippen.', torch: 'Licht',
    searching: 'Suche {isbn} …', found: 'Im Katalog gefunden', notFound: 'Nicht im Katalog – neues Buch anlegen', invalid: 'Keine gültige ISBN',
    insecure: 'Die Kamera funktioniert nur über HTTPS (oder localhost).', engine: 'Scanner: {name}',
    switchCamera: 'Kamera wechseln', chooseCamera: 'Kamera auswählen', cameraN: 'Kamera {n}',
  },
  shelves: {
    title: 'Regale', new: 'Neues Regal', empty: 'Noch keine Regale. Lege dein erstes Regal an – oder lass es beim CSV-Import automatisch erstellen.',
    columns: '{n} Spalten', compartments: '{n} Fächer', edit: 'Regal bearbeiten', name: 'Name', room: 'Raum', description: 'Beschreibung',
    column: 'Spalte', columnLabel: 'Spaltenname', rows: 'Fächer', addColumn: 'Spalte hinzufügen', removeColumn: 'Spalte entfernen',
    autoNames: 'Fächer automatisch benennen', compartmentNames: 'Fachnamen (für CSV-Import, z. B. L2)', preview: 'Vorschau',
    deleteConfirm: 'Regal „{name}“ löschen? Die Bücher bleiben erhalten, verlieren aber ihren Standort.',
    unassigned: '{n} Bücher haben durch die Änderung ihren Platz verloren.', compartmentBooks: 'Fach {name}', emptyCompartment: 'Dieses Fach ist leer.',
    addBooks: 'Bücher hierher stellen', searchUnplaced: 'Buch suchen …', dragHint: 'Tipp: Bücher per Drag & Drop zwischen Fächern verschieben.',
    moved: '{n} Buch/Bücher verschoben', removeFromShelf: 'Aus dem Regal nehmen', unplacedBooks: 'Bücher ohne Standort',
    noShelfPosition: 'Im Regal (ohne Fach)', duplicateName: 'Fachname „{name}“ ist doppelt vergeben.',
    arrangeHint: 'Fächer und Spalten am Griff ziehen, um sie anzuordnen – auch zwischen Spalten. Die Bücher wandern mit. Tastatur: Griff fokussieren und Pfeiltasten nutzen. Fachnamen (z. B. L2) nutzt der CSV-Import zur Zuordnung.',
    moveColumn: 'Spalte {name} verschieben', moveCompartment: 'Fach {name} verschieben', removeCompartment: 'Fach {name} entfernen',
    addCompartment: 'Fach hinzufügen', reverse: 'Reihenfolge umkehren', emptyColumnDrop: 'Fach hierher ziehen',
    emptyColumn: 'Spalte „{name}“ hat keine Fächer – Fach hineinziehen oder Spalte entfernen.', emptyName: 'Jedes Fach braucht einen Namen.',
    booksMoveAlong: '{n} Fach/Fächer mit Büchern haben einen neuen Platz – die Bücher wandern beim Speichern mit.',
    lostBooks: '{n} Bücher stehen in entfernten Fächern und verlieren beim Speichern ihren Standort.',
    lostBooksConfirm: '{n} Bücher verlieren ihren Standort, weil ihre Fächer entfernt wurden. Trotzdem speichern?',
    removeColumnConfirm: 'In dieser Spalte stehen {n} Bücher. Sie verlieren beim Speichern ihren Standort. Spalte entfernen?',
  },
  stats: {
    title: 'Statistik', year: 'Jahr', statusChart: 'Lesestatus', booksTotal: 'Bücher gesamt', pagesRead: 'Gelesene Seiten',
    pagesTotal: 'Seiten im Bestand', avgRating: 'Ø Bewertung', genres: 'Genres', genreRead: 'Gelesen', genreOpen: 'Offen',
    perMonth: 'Gelesen pro Monat {year}', perYear: 'Gelesen pro Jahr', booksAxis: 'Bücher', pagesAxis: 'Seiten',
    pace: 'Lesetempo', avgDays: 'Ø Tage pro Buch', pagesPerDay: 'Seiten pro Tag', thisYear: 'Dieses Jahr', topAuthors: 'Top-Autor:innen',
    withoutDate: '{n} gelesene Bücher haben kein Enddatum und fehlen in der Zeitachse.', noGenre: 'Ohne Genre', others: 'Andere',
    value: 'Bestandswert', readShare: '{p} % gelesen', noData: 'Noch keine Daten für diesen Zeitraum.', table: 'Tabelle', chart: 'Diagramm', noGenreNote: '{n} Bücher ohne Genre sind nicht dargestellt – „Metadaten ergänzen“ in den Einstellungen kann Genres nachtragen.',
  },
  settings: {
    title: 'Einstellungen', profile: 'Profil', language: 'Sprache', currentPassword: 'Aktuelles Passwort', newPassword: 'Neues Passwort',
    importTitle: 'CSV-Import (Bookstats)', importHint: 'Exportiere deine Sammlung in Bookstats als CSV und lade sie hier hoch. Goodreads- und andere CSV-Dateien mit Spalten wie Titel, Autor, ISBN funktionieren ebenfalls.',
    file: 'CSV-Datei', dryRun: 'Nur prüfen (Probelauf, nichts wird gespeichert)', createShelves: 'Unbekannte Standorte (z. B. L2, M4) als neues Regal anlegen',
    shelfName: 'Name des neuen Regals', overwritePersonal: 'Vorhandene Lesedaten mit CSV-Werten überschreiben', overwriteLocation: 'Standort vorhandener Bücher aus CSV übernehmen',
    startImport: 'Import starten', analyse: 'Datei prüfen', importing: 'Importiere …',
    summary: '{created} Bücher neu angelegt, {linked} bereits vorhanden, {skipped} übersprungen, {errors} Fehler.',
    summaryDry: 'Probelauf: {created} würden neu angelegt, {linked} sind bereits vorhanden, {skipped} würden übersprungen, {errors} Fehler.',
    placed: '{n} Bücher einem Fach zugeordnet.', unmatched: 'Nicht zugeordnete Standorte:', unmatchedHint: 'Lege Fächer mit diesen Namen an (Regal bearbeiten) oder aktiviere die automatische Regal-Erstellung und importiere erneut.',
    shelfCreated: 'Neues Regal „{name}“ angelegt: {layout}.', detected: 'Erkannte Spalten: {cols}', messages: 'Hinweise',
    exportTitle: 'Export', exportHint: 'Katalog und deine Lesedaten als Bookstats-kompatible CSV herunterladen.', export: 'CSV exportieren',
    pushTitle: 'Push-Benachrichtigungen', pushHint: 'Erinnerung 3 Tage und 1 Tag vor Ablauf einer Ausleihe sowie am Fälligkeitstag.',
    pushEnable: 'Auf diesem Gerät aktivieren', pushDisable: 'Auf diesem Gerät deaktivieren', pushTest: 'Test senden', pushEnabled: 'Push ist auf diesem Gerät aktiv.',
    pushUnsupported: 'Dieser Browser unterstützt keine Push-Benachrichtigungen. (iOS: App zuerst zum Home-Bildschirm hinzufügen.)',
    pushDenied: 'Benachrichtigungen wurden im Browser blockiert.', pushServerMissing: 'Push ist auf dem Server nicht verfügbar (Composer-Abhängigkeiten fehlen).',
    pushSent: 'Test gesendet an {n} Gerät(e).', metadataTitle: 'Metadaten ergänzen', metadataHint: 'Fehlende Cover, Beschreibungen, Verlage und Genres bei Open Library, der Deutschen Nationalbibliothek und Google Books nachschlagen.',
    metadataRun: 'Jetzt ergänzen', metadataDone: 'Fertig – {updated} Bücher ergänzt.', metadataRemaining: 'Noch {n} Bücher ungeprüft.', metadataUnavailable: 'Metadaten-Dienste nicht erreichbar oder Limit erreicht – später erneut versuchen.', metadataPartial: '{n} Bücher nur teilweise ergänzt, weil eine Quelle nicht erreichbar war (bei Google Books: API-Key in .env eintragen, siehe docs/google-books-api-key.md). Sie werden später erneut versucht.',
    about: 'Über', version: 'Version {v}', installApp: 'App installieren',
    sourceCode: 'Open Source (MIT)',
  },
  users: {
    title: 'Benutzerverwaltung', hint: 'Lege Konten für weitere Personen an. Alle sehen denselben Katalog, Lesedaten bleiben privat.',
    new: 'Neuer Benutzer', create: 'Anlegen', role: 'Rolle', role_admin: 'Admin', role_user: 'Benutzer', you: 'du',
    rolesHint: 'Admins können Benutzer anlegen, Rollen ändern, Passwörter zurücksetzen und Konten löschen. Benutzer können das nicht.',
    initialPassword: 'Start-Passwort', created: 'Benutzer „{name}“ angelegt.', deleted: 'Benutzer „{name}“ gelöscht.',
    deleteConfirm: 'Konto „{name}“ löschen? Die persönlichen Lesedaten dieses Kontos werden gelöscht, die Bücher im Katalog bleiben erhalten.',
    demoteSelf: 'Dir selbst die Admin-Rolle entziehen? Danach kannst du keine Benutzer mehr verwalten.',
    setPassword: 'Passwort setzen', setPasswordFor: 'Neues Passwort für {name}', passwordSet: 'Passwort für {name} geändert.',
    lastLogin: 'zuletzt angemeldet {date}',
    sendInvite: 'Einladung per E-Mail senden', sendInviteOption: 'Einladung per E-Mail senden',
    sendInviteHint: 'Die Person bekommt einen Link und legt ihr Passwort selbst fest. Ohne Haken vergibst du ein Start-Passwort.',
    inviteTitle: 'Einladung für {name}', inviteSent: 'Die Einladung wurde an {email} geschickt.',
    inviteMailFailed: 'Die E-Mail konnte nicht verschickt werden. Gib den Link stattdessen selbst weiter (z. B. per Messenger).',
    inviteMailUnavailable: 'Dieser Server kann keine E-Mails verschicken (PHP mail() ist deaktiviert). Gib den Link selbst weiter.',
    inviteLink: 'Einladungslink', inviteLinkHint: 'Gültig bis {date}, nur einmal verwendbar. Eine neue Einladung macht ältere Links ungültig.',
    invitePending: 'Einladung offen bis {date}',
  },
  impersonate: {
    action: 'Als {name} anmelden', confirm: 'Als „{name}“ anmelden? Du siehst die App dann genau wie diese Person – mit ihren Lesedaten. Über die violette Leiste oben kommst du zurück.',
    banner: 'Du bist als {user} angemeldet (über Admin {admin}).', stop: 'Zurück zu {admin}',
    noPush: 'Während du als andere Person angemeldet bist, lassen sich auf diesem Gerät keine Push-Benachrichtigungen aktivieren.',
  },
  invite: {
    welcome: 'Willkommen, {name}!', intro: 'Du wurdest eingeladen. Lege jetzt dein Passwort fest – danach bist du direkt angemeldet.',
    repeat: 'Passwort wiederholen', mismatch: 'Die Passwörter stimmen nicht überein.', submit: 'Passwort festlegen & loslegen',
    validUntil: 'Link gültig bis {date}', askAdmin: 'Bitte die Person, die dich eingeladen hat, um eine neue Einladung.',
  },
  cron: {
    title: 'Cronjob', url: 'Cron-URL', copy: 'Kopieren', copied: 'URL kopiert.',
    hint: 'Diese URL stündlich aufrufen lassen (Cronjob des Hosters oder z. B. cron-job.org). Sie verschickt die Rückgabe-Erinnerungen und ergänzt Buchdaten.',
    generated: 'Der Token wurde automatisch erzeugt. Alternativ kannst du CRON_TOKEN in der .env festlegen.',
    fromConfig: 'Der Token stammt aus CRON_TOKEN in der .env.', docker: 'Mit Docker oder SSH stattdessen:',
    regenerate: 'Neuen Token erzeugen', regenerated: 'Neuer Token erzeugt – Cronjob-URL beim Hoster aktualisieren!',
    regenerateConfirm: 'Neuen Token erzeugen? Die bisherige URL funktioniert dann nicht mehr.',
  },
  engage: {
    installTitle: '{name} als App installieren', install: 'Installieren', later: 'Später', showGuide: 'Anleitung zeigen',
    installText: 'Mit einem Tipp vom Startbildschirm öffnen, im Vollbild, auch offline – und mit Rückgabe-Erinnerungen.',
    installForPush: 'Auf iPhone und iPad gibt es Erinnerungen nur in der installierten App. Installiere sie, dann kannst du Benachrichtigungen aktivieren.',
    installed: 'Installiert – viel Spaß!', snoozed: 'Okay, ich frage in {n} Tag(en) noch einmal.',
  },
  pushOffer: {
    title: 'Rückgabe-Erinnerungen aktivieren?',
    text: 'Ich erinnere dich 3 Tage und 1 Tag vor Ablauf einer Ausleihe sowie am Fälligkeitstag – damit keine Mahngebühr fällig wird.',
    loanText: 'Soll ich dich an die Rückgabe dieses Buchs erinnern? Du bekommst 3 Tage und 1 Tag vorher sowie am Fälligkeitstag eine Benachrichtigung.',
    enable: 'Aktivieren', retry: 'Erneut versuchen', deniedTitle: 'Benachrichtigungen sind blockiert',
    deniedIntro: 'Du hast Benachrichtigungen für diese Seite abgelehnt. Der Browser fragt deshalb nicht mehr nach – du kannst sie aber selbst wieder erlauben:',
    deniedBrowser: 'Tippe/klicke links in der Adressleiste auf das Schloss- bzw. Einstellungs-Symbol → „Berechtigungen“ / „Website-Einstellungen“ → „Benachrichtigungen“ → „Zulassen“. Danach die Seite neu laden.',
    deniedIos: 'iPhone/iPad: Einstellungen → Mitteilungen → SchmökerSpot → „Mitteilungen erlauben“ einschalten.',
    iosInstallFirst: 'Auf iPhone und iPad funktionieren Push-Benachrichtigungen nur in der installierten App (ab iOS 16.4).',
  },
  install: {
    title: '{name} installieren', done: 'Verstanden', share: 'Teilen', addToHome: 'Zum Home-Bildschirm', openAsWebApp: 'Als Web-App öffnen', add: 'Hinzufügen',
    iosOtherBrowser: 'Am zuverlässigsten klappt das in Safari. In Chrome, Edge oder Firefox findest du „Zum Home-Bildschirm“ ab iOS 16.4 ebenfalls im Teilen-Menü.',
    iosStep1: 'Tippe in Safari auf das Teilen-Symbol',
    iosStep1Newer: 'Bei neueren iOS-Versionen (kompakte Adressleiste): erst unten rechts auf',
    iosStep1Ipad: 'Auf dem iPad sitzt das Teilen-Symbol oben rechts neben der Adressleiste.',
    iosStep2: 'Scrolle im Menü nach unten und tippe auf',
    iosStep2Hint: 'Nicht zu sehen? Ganz unten auf „Mehr“ bzw. „Aktionen bearbeiten …“ tippen und den Eintrag hinzufügen.',
    iosStep3: 'Lass den Schalter', iosStep3b: 'eingeschaltet (falls angezeigt) und tippe oben rechts auf',
    iosStep4: 'Öffne SchmökerSpot ab jetzt immer über das neue Symbol auf deinem Home-Bildschirm.',
    iosPush: 'Erst in der installierten App kannst du Rückgabe-Erinnerungen per Push aktivieren – SchmökerSpot fragt dich dort automatisch.',
    iosPushOld: 'Hinweis: Push-Benachrichtigungen gibt es auf dem iPhone erst ab iOS 16.4. Bitte iOS aktualisieren, um Erinnerungen zu bekommen.',
    inAppIntro: 'Du hast den Link in einer anderen App geöffnet (z. B. Instagram oder Google). Von dort aus lässt sich nichts installieren – öffne die Seite zuerst in Safari:',
    inAppStep1: 'Tippe auf das Menü bzw. Teilen-Symbol der App',
    inAppStep2: 'Wähle „In Safari öffnen“ bzw. „Im Browser öffnen“',
    inAppStep3: 'Folge dort der Anleitung zum Installieren. Alternativ: Link kopieren und in Safari einfügen.',
    copyLink: 'Link kopieren', linkCopied: 'Link kopiert – jetzt in Safari einfügen.',
    androidStep1: 'Tippe oben rechts im Browser auf das Menü',
    androidStep2: 'Wähle „App installieren“ bzw. „Zum Startbildschirm hinzufügen“ (Firefox: „Installieren“).',
    androidStep3: 'Bestätige mit „Installieren“ – die App erscheint auf deinem Startbildschirm.',
    macSafari: 'Safari auf dem Mac: Menü „Ablage“ → „Zum Dock hinzufügen …“ (ab macOS Sonoma).',
    desktopStep1: 'Klicke rechts in der Adressleiste auf das Installieren-Symbol',
    desktopStep2Chrome: 'Oder: Menü ⋮ → „Streamen, speichern und teilen“ → „Seite als App installieren …“ (je nach Chrome-Version auch „App installieren“).',
    desktopStep2Edge: 'Oder: Menü ⋯ → „Apps“ → „Diese Website als App installieren“.',
    desktopNotOffered: 'Fehlt beides, ist die App auf diesem Gerät vermutlich schon installiert – oder die Seite wird nicht über HTTPS aufgerufen.',
    alreadyInstalled: '✓ Du nutzt die installierte App.',
    unsupported: 'Dieser Browser kann Web-Apps nicht installieren. Nutze Chrome oder Edge (Windows, Android) bzw. Safari (iPhone, iPad, Mac).',
  },
  onboarding: {
    title: 'Einführung', start: 'Los geht’s', next: 'Weiter', finish: 'Fertig', skip: 'Überspringen',
    restart: 'Einführung erneut ansehen', adminBadge: 'Für Admins',
    welcome: {
      title: 'Willkommen, {name}!', text: '{app} ist eure gemeinsame Bücherverwaltung. In einer Minute zeige ich dir das Wichtigste.',
      p1: 'Alle sehen denselben Katalog: Bücher, Regale und Ausleihen.', p2: 'Dein Lesestand, deine Bewertungen und Notizen gehören nur dir.', p3: 'Funktioniert auch offline – Änderungen werden später synchronisiert.',
    },
    find: {
      title: 'Bücher finden & hinzufügen', text: 'Ein Buch in der Hand? Einfach den Barcode scannen.',
      p1: 'Der runde Scannen-Knopf unten öffnet die Kamera und erkennt die ISBN.', p2: 'Steht das Buch schon im Katalog, siehst du sofort, wo es steht. Sonst füllt {app} Titel, Autor & Co. automatisch aus.', p3: 'Oben auf der Startseite und unter „Bücher“ findest du alles über die Suche und Filter.',
    },
    reading: {
      title: 'Lesestand & Statistik', text: 'In jedem Buch kannst du festhalten, wie weit du bist.',
      p1: 'Status (ungelesen, am Lesen, gelesen), gelesene Seiten, Sterne und Notizen.', p2: 'Auf der Startseite aktualisierst du den Fortschritt deiner aktuellen Bücher mit einem Tipp.', p3: 'Die Statistik zeigt Seiten, Genres und dein Lesetempo pro Monat und Jahr.',
    },
    shelves: {
      title: 'Regale & Standorte', text: 'Jedes eigene Buch hat einen Platz: Regal › Fach.',
      p1: 'Unter „Regale“ siehst du jedes Regal mit seinen Fächern und Buchrücken.', p2: 'Bücher lassen sich per Drag & Drop in ein anderes Fach ziehen oder über „Verschieben nach …“ umstellen.', p3: 'Der Filter „Ohne Standort“ zeigt Bücher, die noch einen Platz brauchen.',
    },
    loans: {
      title: 'Ausleihen & Erinnerungen', text: 'Bücher aus der Bücherei oder von Freunden mit Rückgabedatum.',
      p1: 'Beim Anlegen „Bibliotheks-Ausleihe“ wählen und das Rückgabedatum eintragen.', p2: 'Die Startseite zeigt anstehende Rückgaben, knappe Fristen sind markiert.', p3: 'Mit Push erinnert dich {app} 3 Tage und 1 Tag vorher sowie am Fälligkeitstag.',
      pushLater: 'Erinnerungen kannst du später in den Einstellungen aktivieren.',
    },
    install: {
      title: 'Als App installieren', text: 'Am bequemsten nutzt du {app} als App auf dem Handy oder Computer.',
      p1: 'Startet mit einem Tipp vom Startbildschirm, im Vollbild.', p2: 'Funktioniert auch ohne Internet.', p3: 'Auf iPhone und iPad gibt es Erinnerungen nur in der installierten App.',
      hideGuide: 'Anleitung ausblenden',
    },
    adminImport: {
      title: 'Sammlung importieren', text: 'Du hast schon eine Liste, z. B. aus Bookstats? Dann musst du nichts abtippen.',
      p1: 'Einstellungen → CSV-Import: Bookstats-, Goodreads- und andere CSV-Dateien.', p2: 'Standort-Codes wie L2 oder M4 werden erkannt und auf Wunsch automatisch als Regal angelegt.', p3: 'Mit „Datei prüfen“ siehst du vorher, was passieren würde. Cover und fehlende Daten werden danach ergänzt.',
    },
    adminShelves: {
      title: 'Regale einrichten', text: 'Jedes Regal besteht aus Spalten mit beliebig vielen Fächern.',
      p1: 'Regal bearbeiten: Fächer und Spalten am Griff per Drag & Drop anordnen – die Bücher wandern mit.', p2: '„Reihenfolge umkehren“, wenn die Fächer von unten nach oben nummeriert sind.', p3: 'Die Fachnamen (z. B. L2) nutzt der CSV-Import zur Zuordnung.',
    },
    adminUsers: {
      title: 'Familie & Freunde einladen', text: 'Unter Einstellungen → Benutzerverwaltung legst du weitere Konten an.',
      p1: 'Rolle „Admin“ darf Benutzer verwalten, „Benutzer“ nicht.', p2: 'Per Einladungs-E-Mail legt jede Person ihr Passwort selbst fest.', p3: 'Über die Maske kannst du dich zur Hilfe als jemand anderes anmelden.',
    },
    adminCron: {
      title: 'Cronjob & Buchdaten', text: 'Damit Erinnerungen pünktlich kommen, sollte eine URL stündlich aufgerufen werden.',
      p1: 'Die fertige Cron-URL steht unter Einstellungen → Cronjob (beim Hoster eintragen oder cron-job.org).', p2: 'Der Cronjob lädt außerdem Cover herunter und ergänzt fehlende Buchdaten.', p3: 'Optional: einen kostenlosen Google-Books-Key in der .env eintragen (siehe docs/).',
    },
    done: {
      title: 'Alles bereit!', text: 'Viel Spaß beim Schmökern. Die Einführung findest du jederzeit wieder unter Einstellungen.',
      p1: 'Tipp: Mit dem Scanner geht das Erfassen am schnellsten.', p2: 'Fragen zu einem Buch? Einfach antippen – dort steht alles.', p3: 'Änderungen ohne Internet werden automatisch nachgereicht.',
      scan: 'Erstes Buch scannen', import: 'CSV importieren', users: 'Benutzer einladen', books: 'Bücher ansehen',
    },
  },
  newLocation: {
    button: 'Neuer Standort', title: 'Neuen Standort anlegen', shelfLabel: 'Regal', compartment: 'Neues Fach', shelf: 'Neues Regal',
    newColumn: '+ Neue Spalte', compartmentName: 'Name des Fachs', createCompartment: 'Fach anlegen', createShelf: 'Regal anlegen',
    compartmentHint: 'Das Fach kommt unten in die Spalte. Anordnen kannst du es später unter „Regal bearbeiten“ – Bücher bleiben dabei an ihrem Platz.',
    columnCount: 'Spalten', rowCount: 'Fächer pro Spalte', shelfPlaceholder: 'z. B. Bücherregal Flur',
    shelfHint: 'Die Fächer heißen automatisch A1, A2 … B1 … – umbenennen und anordnen geht unter „Regal bearbeiten“.',
    created: 'Standort angelegt.', noShelves: 'Noch kein Regal vorhanden.',
  },
  sync: {
    done: '{n} Offline-Änderung(en) synchronisiert.', conflicts: '{n} Änderung(en) waren veraltet – neuere Daten vom Server behalten.',
    errors: '{n} Änderung(en) konnten nicht übernommen werden.', queued: 'Offline gespeichert – wird synchronisiert, sobald du online bist.',
  },
  errors: {
    offline: 'Keine Verbindung zum Server.', server_error: 'Serverfehler. Bitte später erneut versuchen.', request_failed: 'Anfrage fehlgeschlagen.',
    unauthenticated: 'Bitte melde dich an.', forbidden: 'Keine Berechtigung.', not_found: 'Nicht gefunden.', book_not_found: 'Buch nicht gefunden.',
    shelf_not_found: 'Regal nicht gefunden.', invalid_credentials: 'Benutzername oder Passwort falsch.',
    too_many_attempts: 'Zu viele Fehlversuche. Bitte 15 Minuten warten.', registration_closed: 'Die Registrierung ist geschlossen.',
    validation_failed: 'Bitte prüfe deine Eingaben.', duplicate_isbn: 'Ein Buch mit dieser ISBN existiert bereits.',
    csv_empty: 'Die CSV-Datei ist leer.', csv_too_large: 'Die Datei ist zu groß (max. 15 MB).', csv_missing_title_column: 'Keine Titel-Spalte gefunden. Ist das eine Bücher-CSV?',
    upload_failed: 'Upload fehlgeschlagen.', csrf_header_missing: 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.',
    method_not_allowed: 'Methode nicht erlaubt.', invalid_json: 'Ungültige Anfrage.', unknown_action: 'Unbekannte Aktion.',
    user_not_found: 'Benutzer nicht gefunden.', last_admin: 'Es muss mindestens ein Admin bleiben.', cannot_delete_self: 'Du kannst dein eigenes Konto nicht löschen.',
    invite_invalid: 'Dieser Einladungslink ist ungültig oder wurde schon verwendet.', invite_expired: 'Dieser Einladungslink ist abgelaufen.',
    cannot_impersonate_self: 'Du kannst dich nicht als du selbst anmelden.', already_impersonating: 'Du bist bereits als andere Person angemeldet.', not_impersonating: 'Du bist nicht als andere Person angemeldet.', impersonation_no_push: 'Während du als andere Person angemeldet bist, ist Push nicht möglich.',
    cron_token_too_short: 'CRON_TOKEN in der .env ist zu kurz (mind. 16 Zeichen).', cron_token_from_config: 'Der Token ist in der .env festgelegt und kann hier nicht geändert werden.',
  },
  validation: {
    required: 'Pflichtfeld', invalid_isbn: 'Ungültige ISBN', invalid_url: 'Ungültige URL (http/https)', invalid_date: 'Ungültiges Datum',
    invalid_choice: 'Ungültige Auswahl', invalid_username: '3–50 Zeichen: Buchstaben, Ziffern, . _ -', invalid_email: 'Ungültige E-Mail-Adresse',
    password_too_short: 'Mindestens 8 Zeichen', username_taken: 'Benutzername vergeben', email_taken: 'E-Mail bereits registriert',
    wrong_password: 'Passwort falsch', invalid_rating: '1–5 Sterne', before_start: 'Liegt vor dem Startdatum', compartment_not_found: 'Fach existiert nicht',
    at_least_one_column: 'Mindestens eine Spalte', too_many_columns: 'Zu viele Spalten', too_many_rows: 'Zu viele Fächer',
    duplicate_compartment_name: 'Fachnamen müssen eindeutig sein',
  },
  import: {
    missing_title: 'Zeile ohne Titel', duplicate_in_file: 'Doppelt in der Datei', row_failed: 'Fehler',
  },
}

const en = {
  app: { name: 'BookSpot', tagline: 'Your books. Your shelves. Your stats.' },
  nav: { home: 'Home', books: 'Books', scan: 'Scan', shelves: 'Shelves', stats: 'Stats', settings: 'Settings' },
  common: {
    save: 'Save', cancel: 'Cancel', delete: 'Delete', edit: 'Edit', back: 'Back', close: 'Close', add: 'Add', search: 'Search',
    loading: 'Loading …', none: 'None', yes: 'Yes', no: 'No', more: 'Show more', all: 'All', retry: 'Retry', optional: 'optional',
    books: '{n} books', book: '1 book', pages: '{n} pages', offline: 'Offline – changes will sync later', pending: '{n} change(s) waiting to sync',
    syncing: 'Syncing …', confirmDelete: 'Really delete?', saved: 'Saved', unknownAuthor: 'Unknown author', today: 'today',
    update: 'New version available', reload: 'Reload', select: 'Please choose', moveTo: 'Move to …',
  },
  auth: {
    login: 'Sign in', register: 'Create account', logout: 'Sign out', username: 'Username', email: 'Email', password: 'Password',
    loginField: 'Username or email', setupTitle: 'Welcome! Create the first account.',
    setupHint: 'The first account becomes admin. Create further accounts afterwards under Settings → User management.',
    noAccount: 'No account yet?', haveAccount: 'Already registered?', passwordHint: 'At least 8 characters',
  },
  dashboard: {
    hello: 'Hi {name}!', searchPlaceholder: 'Search title, author or ISBN …', dueTitle: 'Upcoming returns', dueEmpty: 'No open loans.',
    readingTitle: 'Currently reading', readingEmpty: 'You are not reading anything. Time for a new book?', lentTitle: 'Lent out',
    quickScan: 'Scan book', quickAdd: 'Add manually', quickImport: 'Import CSV', unplaced: '{n} books have no shelf position yet.',
    recent: 'Recently added', enrich: '{n} books are missing covers/descriptions.', enrichAction: 'Fill in now', enrichRunning: 'Fetching metadata … {n} left',
    emptyTitle: 'No books yet', emptyText: 'Scan your first book or import your Bookstats collection.', progressSave: 'Save progress',
    statBooks: 'Books', statRead: 'Read', statPages: 'Pages read',
  },
  due: {
    overdue: '{n} day(s) overdue', today: 'Due today', tomorrow: 'Due tomorrow', inDays: 'Due in {n} days', noDate: 'No due date',
    returned: 'Returned', markReturned: 'Mark as returned', extend: 'Extend',
  },
  books: {
    title: 'Books', count: '{n} books', empty: 'No books found.', sort: 'Sort', genre: 'Genre', allGenres: 'All genres',
    sortTitle: 'Title', sortAuthors: 'Author', sortRecent: 'Newest', sortPages: 'Pages', sortYear: 'Year', sortDue: 'Due date',
    filterUnplaced: 'No location', filterLent: 'Lent out', filterLibrary: 'Loans', offlineList: 'Offline copy from {date}',
    selectMode: 'Select', selected: '{n} selected', moveSelected: 'Move selection',
  },
  status: { unread: 'Unread', reading: 'Reading', read: 'Read', abandoned: 'Abandoned' },
  entry: {
    owned: 'Own book', library_loan: 'Library loan', personal_loan: 'Borrowed privately',
    ownedHint: 'Bought or gifted – with a shelf position', libraryHint: 'From the library, with due date', personalHint: 'Borrowed from friends/family',
  },
  book: {
    isbn: 'ISBN', title: 'Title', authors: 'Author(s)', publisher: 'Publisher', year: 'Year', pages: 'Pages', genre: 'Genre',
    description: 'Description', cover: 'Cover URL', format: 'Format', price: 'Price', acquired: 'Acquired on', language: 'Language',
    type: 'Type', location: 'Location', noLocation: 'No location', setLocation: 'Set location', loanFrom: 'Borrowed from',
    library: 'Library', person: 'Person', loanStart: 'Borrowed on', loanDue: 'Due on', borrower: 'Borrowed by (user)',
    lentTo: 'Lent to', lentAt: 'Lent on', lend: 'Lend', lendTo: 'To whom?', gotBack: 'Got it back',
    status: 'Catalogue status', statusActive: 'Available', statusReturned: 'Returned', statusDiscarded: 'Discarded',
    newTitle: 'Add book', editTitle: 'Edit book', fetch: 'Fetch data', fetching: 'Searching Open Library, DNB and Google Books …',
    fetched: 'Data applied ({source}).', notFoundOnline: 'No online data found – please fill in manually.', lookupUnavailable: 'The book databases are not reachable right now – try again later or fill in manually.',
    exists: 'This book is already in the catalogue.', openExisting: 'Open existing', created: 'Book added', deleted: 'Book deleted',
    personal: 'My reading', rating: 'Rating', pagesRead: 'Pages read', started: 'Started', finished: 'Finished', notes: 'Notes',
    otherReaders: 'Other readers', details: 'Details', addedBy: 'Added by {name}', initialStatus: 'Reading status',
    deleteConfirm: 'Really delete “{title}” from the shared catalogue? Reading data of all users will be lost.',
    progress: '{read} of {total} pages', markRead: 'Finished', startReading: 'Start reading', catalog: 'Catalogue',
  },
  scan: {
    title: 'Scan barcode', hint: 'Hold the EAN/ISBN barcode on the back cover inside the frame.', start: 'Start camera', stop: 'Stop camera',
    manual: 'Enter ISBN manually', manualPlaceholder: 'e.g. 978-3-404-13987-3', go: 'Search',
    noCamera: 'No camera available or access denied. You can type the ISBN instead.', torch: 'Light',
    searching: 'Searching {isbn} …', found: 'Found in catalogue', notFound: 'Not in catalogue – add new book', invalid: 'Not a valid ISBN',
    insecure: 'The camera only works over HTTPS (or localhost).', engine: 'Scanner: {name}',
    switchCamera: 'Switch camera', chooseCamera: 'Choose camera', cameraN: 'Camera {n}',
  },
  shelves: {
    title: 'Shelves', new: 'New shelf', empty: 'No shelves yet. Create your first one – or let the CSV import create it automatically.',
    columns: '{n} columns', compartments: '{n} compartments', edit: 'Edit shelf', name: 'Name', room: 'Room', description: 'Description',
    column: 'Column', columnLabel: 'Column label', rows: 'Compartments', addColumn: 'Add column', removeColumn: 'Remove column',
    autoNames: 'Auto-name compartments', compartmentNames: 'Compartment names (matched by CSV import, e.g. L2)', preview: 'Preview',
    deleteConfirm: 'Delete shelf “{name}”? Books are kept but lose their location.',
    unassigned: '{n} books lost their position because of this change.', compartmentBooks: 'Compartment {name}', emptyCompartment: 'This compartment is empty.',
    addBooks: 'Put books here', searchUnplaced: 'Search book …', dragHint: 'Tip: drag & drop books between compartments.',
    moved: '{n} book(s) moved', removeFromShelf: 'Remove from shelf', unplacedBooks: 'Books without location',
    noShelfPosition: 'On this shelf (no compartment)', duplicateName: 'Compartment name “{name}” is used twice.',
    arrangeHint: 'Drag compartments and columns by their handle to arrange them – also between columns. The books move along. Keyboard: focus a handle and use the arrow keys. Compartment names (e.g. L2) are used by the CSV import.',
    moveColumn: 'Move column {name}', moveCompartment: 'Move compartment {name}', removeCompartment: 'Remove compartment {name}',
    addCompartment: 'Add compartment', reverse: 'Reverse order', emptyColumnDrop: 'Drag a compartment here',
    emptyColumn: 'Column “{name}” has no compartments – drag one in or remove the column.', emptyName: 'Every compartment needs a name.',
    booksMoveAlong: '{n} compartment(s) with books have a new position – the books move along on save.',
    lostBooks: '{n} books are in removed compartments and lose their location on save.',
    lostBooksConfirm: '{n} books lose their location because their compartments were removed. Save anyway?',
    removeColumnConfirm: 'This column holds {n} books. They lose their location on save. Remove the column?',
  },
  stats: {
    title: 'Statistics', year: 'Year', statusChart: 'Reading status', booksTotal: 'Books total', pagesRead: 'Pages read',
    pagesTotal: 'Pages in collection', avgRating: 'Avg. rating', genres: 'Genres', genreRead: 'Read', genreOpen: 'Open',
    perMonth: 'Read per month {year}', perYear: 'Read per year', booksAxis: 'Books', pagesAxis: 'Pages',
    pace: 'Reading pace', avgDays: 'Avg. days per book', pagesPerDay: 'Pages per day', thisYear: 'This year', topAuthors: 'Top authors',
    withoutDate: '{n} read books have no finish date and are missing from the timeline.', noGenre: 'No genre', others: 'Others',
    value: 'Collection value', readShare: '{p} % read', noData: 'No data for this period yet.', table: 'Table', chart: 'Chart', noGenreNote: '{n} books without genre are not shown – “Fill in metadata” in settings can add genres.',
  },
  settings: {
    title: 'Settings', profile: 'Profile', language: 'Language', currentPassword: 'Current password', newPassword: 'New password',
    importTitle: 'CSV import (Bookstats)', importHint: 'Export your collection from Bookstats as CSV and upload it here. Goodreads and other CSV files with columns like title, author, ISBN work too.',
    file: 'CSV file', dryRun: 'Check only (dry run, nothing is saved)', createShelves: 'Create a new shelf for unknown locations (e.g. L2, M4)',
    shelfName: 'Name of the new shelf', overwritePersonal: 'Overwrite existing reading data with CSV values', overwriteLocation: 'Move existing books to the CSV location',
    startImport: 'Start import', analyse: 'Check file', importing: 'Importing …',
    summary: '{created} books created, {linked} already existed, {skipped} skipped, {errors} errors.',
    summaryDry: 'Dry run: {created} would be created, {linked} already exist, {skipped} would be skipped, {errors} errors.',
    placed: '{n} books assigned to a compartment.', unmatched: 'Unmatched locations:', unmatchedHint: 'Create compartments with these names (edit shelf) or enable automatic shelf creation and import again.',
    shelfCreated: 'New shelf “{name}” created: {layout}.', detected: 'Detected columns: {cols}', messages: 'Notes',
    exportTitle: 'Export', exportHint: 'Download catalogue and your reading data as Bookstats-compatible CSV.', export: 'Export CSV',
    pushTitle: 'Push notifications', pushHint: 'Reminders 3 days and 1 day before a loan is due and on the due date.',
    pushEnable: 'Enable on this device', pushDisable: 'Disable on this device', pushTest: 'Send test', pushEnabled: 'Push is active on this device.',
    pushUnsupported: 'This browser does not support push notifications. (iOS: add the app to the home screen first.)',
    pushDenied: 'Notifications are blocked in the browser.', pushServerMissing: 'Push is not available on the server (Composer dependencies missing).',
    pushSent: 'Test sent to {n} device(s).', metadataTitle: 'Fill in metadata', metadataHint: 'Look up missing covers, descriptions, publishers and genres on Open Library, the German National Library and Google Books.',
    metadataRun: 'Fill in now', metadataDone: 'Done – {updated} books updated.', metadataRemaining: '{n} books not checked yet.', metadataUnavailable: 'Metadata services unreachable or rate-limited – try again later.', metadataPartial: '{n} books only partially completed because a source was unreachable (Google Books: set an API key in .env, see docs/google-books-api-key.md). They will be retried later.',
    about: 'About', version: 'Version {v}', installApp: 'Install app',
    sourceCode: 'Open source (MIT)',
  },
  users: {
    title: 'User management', hint: 'Create accounts for other people. Everyone sees the same catalogue; reading data stays private.',
    new: 'New user', create: 'Create', role: 'Role', role_admin: 'Admin', role_user: 'User', you: 'you',
    rolesHint: 'Admins can create users, change roles, reset passwords and delete accounts. Users cannot.',
    initialPassword: 'Initial password', created: 'User “{name}” created.', deleted: 'User “{name}” deleted.',
    deleteConfirm: 'Delete account “{name}”? Its personal reading data is deleted, the books in the catalogue are kept.',
    demoteSelf: 'Remove your own admin role? You will no longer be able to manage users.',
    setPassword: 'Set password', setPasswordFor: 'New password for {name}', passwordSet: 'Password for {name} changed.',
    lastLogin: 'last sign-in {date}',
    sendInvite: 'Send invitation email', sendInviteOption: 'Send invitation email',
    sendInviteHint: 'The person gets a link and chooses their own password. Without the tick you set an initial password.',
    inviteTitle: 'Invitation for {name}', inviteSent: 'The invitation was sent to {email}.',
    inviteMailFailed: 'The email could not be sent. Pass the link on yourself instead (e.g. via messenger).',
    inviteMailUnavailable: 'This server cannot send emails (PHP mail() is disabled). Pass the link on yourself.',
    inviteLink: 'Invitation link', inviteLinkHint: 'Valid until {date}, can be used once. A new invitation invalidates older links.',
    invitePending: 'Invitation open until {date}',
  },
  impersonate: {
    action: 'Sign in as {name}', confirm: 'Sign in as “{name}”? You will see the app exactly like this person – with their reading data. Use the purple bar at the top to return.',
    banner: 'You are signed in as {user} (via admin {admin}).', stop: 'Back to {admin}',
    noPush: 'While you are signed in as someone else, push notifications cannot be enabled on this device.',
  },
  invite: {
    welcome: 'Welcome, {name}!', intro: 'You have been invited. Choose your password – you will be signed in right away.',
    repeat: 'Repeat password', mismatch: 'The passwords do not match.', submit: 'Set password & get started',
    validUntil: 'Link valid until {date}', askAdmin: 'Please ask the person who invited you for a new invitation.',
  },
  cron: {
    title: 'Cron job', url: 'Cron URL', copy: 'Copy', copied: 'URL copied.',
    hint: 'Have this URL called hourly (your hoster’s cron jobs or e.g. cron-job.org). It sends due-date reminders and completes book data.',
    generated: 'The token was generated automatically. Alternatively set CRON_TOKEN in .env.',
    fromConfig: 'The token comes from CRON_TOKEN in .env.', docker: 'With Docker or SSH instead:',
    regenerate: 'Generate new token', regenerated: 'New token generated – update the cron job URL at your hoster!',
    regenerateConfirm: 'Generate a new token? The current URL will stop working.',
  },
  engage: {
    installTitle: 'Install {name} as an app', install: 'Install', later: 'Later', showGuide: 'Show me how',
    installText: 'Open it with one tap from your home screen, full screen, offline – and with due-date reminders.',
    installForPush: 'On iPhone and iPad reminders only work in the installed app. Install it, then you can enable notifications.',
    installed: 'Installed – enjoy!', snoozed: 'Okay, I will ask again in {n} day(s).',
  },
  pushOffer: {
    title: 'Enable due-date reminders?',
    text: 'I will remind you 3 days and 1 day before a loan is due and on the due date – so you never pay a late fee.',
    loanText: 'Shall I remind you to return this book? You will get a notification 3 days and 1 day before and on the due date.',
    enable: 'Enable', retry: 'Try again', deniedTitle: 'Notifications are blocked',
    deniedIntro: 'You declined notifications for this site, so the browser will not ask again – but you can allow them yourself:',
    deniedBrowser: 'Tap/click the lock or settings icon on the left of the address bar → “Permissions” / “Site settings” → “Notifications” → “Allow”. Then reload the page.',
    deniedIos: 'iPhone/iPad: Settings → Notifications → SchmökerSpot → turn on “Allow Notifications”.',
    iosInstallFirst: 'On iPhone and iPad push notifications only work in the installed app (iOS 16.4+).',
  },
  install: {
    title: 'Install {name}', done: 'Got it', share: 'Share', addToHome: 'Add to Home Screen', openAsWebApp: 'Open as Web App', add: 'Add',
    iosOtherBrowser: 'This works most reliably in Safari. In Chrome, Edge or Firefox “Add to Home Screen” is in the share menu as well (iOS 16.4+).',
    iosStep1: 'In Safari, tap the share icon',
    iosStep1Newer: 'On newer iOS versions (compact address bar): first tap',
    iosStep1Ipad: 'On iPad the share icon is at the top right next to the address bar.',
    iosStep2: 'Scroll down in the menu and tap',
    iosStep2Hint: 'Not there? Tap “More” / “Edit Actions …” at the very bottom and add it.',
    iosStep3: 'Keep', iosStep3b: 'switched on (if shown) and tap at the top right on',
    iosStep4: 'From now on always open the app via the new icon on your home screen.',
    iosPush: 'Only inside the installed app can you enable push reminders – the app will ask you there automatically.',
    iosPushOld: 'Note: push notifications on iPhone require iOS 16.4 or newer. Please update iOS to get reminders.',
    inAppIntro: 'You opened the link inside another app (e.g. Instagram or Google). Nothing can be installed from there – open the page in Safari first:',
    inAppStep1: 'Tap the app’s menu or share icon',
    inAppStep2: 'Choose “Open in Safari” / “Open in browser”',
    inAppStep3: 'Follow the installation guide there. Alternatively copy the link and paste it into Safari.',
    copyLink: 'Copy link', linkCopied: 'Link copied – now paste it into Safari.',
    androidStep1: 'Tap the browser menu at the top right',
    androidStep2: 'Choose “Install app” or “Add to Home screen” (Firefox: “Install”).',
    androidStep3: 'Confirm with “Install” – the app appears on your home screen.',
    macSafari: 'Safari on Mac: menu “File” → “Add to Dock …” (macOS Sonoma or newer).',
    desktopStep1: 'Click the install icon on the right of the address bar',
    desktopStep2Chrome: 'Or: menu ⋮ → “Cast, save and share” → “Install page as app …” (in some Chrome versions “Install app”).',
    desktopStep2Edge: 'Or: menu ⋯ → “Apps” → “Install this site as an app”.',
    desktopNotOffered: 'If neither is there, the app is probably already installed on this device – or the page is not served over HTTPS.',
    alreadyInstalled: '✓ You are using the installed app.',
    unsupported: 'This browser cannot install web apps. Use Chrome or Edge (Windows, Android) or Safari (iPhone, iPad, Mac).',
  },
  onboarding: {
    title: 'Introduction', start: 'Let’s go', next: 'Next', finish: 'Done', skip: 'Skip',
    restart: 'Show introduction again', adminBadge: 'For admins',
    welcome: {
      title: 'Welcome, {name}!', text: '{app} is your shared book collection. Let me show you the essentials in a minute.',
      p1: 'Everyone sees the same catalogue: books, shelves and loans.', p2: 'Your reading progress, ratings and notes are yours alone.', p3: 'Works offline too – changes are synced later.',
    },
    find: {
      title: 'Find & add books', text: 'Got a book in your hand? Just scan its barcode.',
      p1: 'The round scan button at the bottom opens the camera and reads the ISBN.', p2: 'If the book is already in the catalogue you see right away where it is. Otherwise {app} fills in title, author etc. automatically.', p3: 'Search and filters are on the home page and under “Books”.',
    },
    reading: {
      title: 'Reading progress & stats', text: 'Every book keeps track of how far you are.',
      p1: 'Status (unread, reading, read), pages read, stars and notes.', p2: 'Update the progress of your current books with one tap on the home page.', p3: 'Statistics show pages, genres and your reading pace per month and year.',
    },
    shelves: {
      title: 'Shelves & locations', text: 'Every own book has a place: shelf › compartment.',
      p1: 'Under “Shelves” you see every shelf with its compartments and book spines.', p2: 'Drag books into another compartment or use “Move to …”.', p3: 'The “No location” filter shows books that still need a place.',
    },
    loans: {
      title: 'Loans & reminders', text: 'Books from the library or friends with a due date.',
      p1: 'Choose “Library loan” when adding and enter the due date.', p2: 'The home page lists upcoming returns, tight deadlines are highlighted.', p3: 'With push {app} reminds you 3 days and 1 day before and on the due date.',
      pushLater: 'You can enable reminders later in the settings.',
    },
    install: {
      title: 'Install as an app', text: '{app} is most convenient as an app on your phone or computer.',
      p1: 'Opens with one tap from the home screen, full screen.', p2: 'Works without internet as well.', p3: 'On iPhone and iPad reminders only work in the installed app.',
      hideGuide: 'Hide guide',
    },
    adminImport: {
      title: 'Import your collection', text: 'Already have a list, e.g. from Bookstats? No need to type anything.',
      p1: 'Settings → CSV import: Bookstats, Goodreads and other CSV files.', p2: 'Location codes like L2 or M4 are recognised and can be turned into a shelf automatically.', p3: '“Check file” shows what would happen first. Covers and missing data are completed afterwards.',
    },
    adminShelves: {
      title: 'Set up shelves', text: 'Each shelf consists of columns with any number of compartments.',
      p1: 'Edit shelf: drag compartments and columns by their handle – the books move along.', p2: '“Reverse order” when compartments are numbered from the bottom up.', p3: 'Compartment names (e.g. L2) are used by the CSV import.',
    },
    adminUsers: {
      title: 'Invite family & friends', text: 'Create more accounts under Settings → User management.',
      p1: 'Role “Admin” may manage users, “User” may not.', p2: 'With an invitation email everyone chooses their own password.', p3: 'Use the mask to sign in as someone else to help them.',
    },
    adminCron: {
      title: 'Cron job & book data', text: 'For reminders to arrive on time, a URL should be called hourly.',
      p1: 'The cron URL is under Settings → Cron job (add it at your hoster or cron-job.org).', p2: 'The cron job also downloads covers and completes missing book data.', p3: 'Optional: add a free Google Books key to .env (see docs/).',
    },
    done: {
      title: 'All set!', text: 'Happy reading. You can find this introduction again in the settings at any time.',
      p1: 'Tip: the scanner is the fastest way to add books.', p2: 'Questions about a book? Just tap it – everything is there.', p3: 'Changes made offline are synced automatically.',
      scan: 'Scan first book', import: 'Import CSV', users: 'Invite users', books: 'Browse books',
    },
  },
  newLocation: {
    button: 'New location', title: 'Create a new location', shelfLabel: 'Shelf', compartment: 'New compartment', shelf: 'New shelf',
    newColumn: '+ New column', compartmentName: 'Compartment name', createCompartment: 'Create compartment', createShelf: 'Create shelf',
    compartmentHint: 'The compartment is added at the bottom of the column. Arrange it later under “Edit shelf” – books stay where they are.',
    columnCount: 'Columns', rowCount: 'Compartments per column', shelfPlaceholder: 'e.g. hallway bookcase',
    shelfHint: 'Compartments are named A1, A2 … B1 … automatically – rename and arrange them under “Edit shelf”.',
    created: 'Location created.', noShelves: 'No shelf yet.',
  },
  sync: {
    done: '{n} offline change(s) synced.', conflicts: '{n} change(s) were outdated – kept newer server data.',
    errors: '{n} change(s) could not be applied.', queued: 'Saved offline – will sync when you are back online.',
  },
  errors: {
    offline: 'No connection to the server.', server_error: 'Server error. Please try again later.', request_failed: 'Request failed.',
    unauthenticated: 'Please sign in.', forbidden: 'Not allowed.', not_found: 'Not found.', book_not_found: 'Book not found.',
    shelf_not_found: 'Shelf not found.', invalid_credentials: 'Wrong username or password.', too_many_attempts: 'Too many failed attempts. Please wait 15 minutes.',
    registration_closed: 'Registration is closed.', validation_failed: 'Please check your input.', duplicate_isbn: 'A book with this ISBN already exists.',
    csv_empty: 'The CSV file is empty.', csv_too_large: 'The file is too large (max. 15 MB).', csv_missing_title_column: 'No title column found. Is this a book CSV?',
    upload_failed: 'Upload failed.', csrf_header_missing: 'Security check failed. Please reload.', method_not_allowed: 'Method not allowed.',
    invalid_json: 'Invalid request.', unknown_action: 'Unknown action.',
    user_not_found: 'User not found.', last_admin: 'At least one admin must remain.', cannot_delete_self: 'You cannot delete your own account.',
    invite_invalid: 'This invitation link is invalid or has already been used.', invite_expired: 'This invitation link has expired.',
    cannot_impersonate_self: 'You cannot sign in as yourself.', already_impersonating: 'You are already signed in as someone else.', not_impersonating: 'You are not signed in as someone else.', impersonation_no_push: 'Push is not possible while signed in as someone else.',
    cron_token_too_short: 'CRON_TOKEN in .env is too short (min. 16 characters).', cron_token_from_config: 'The token is set in .env and cannot be changed here.',
  },
  validation: {
    required: 'Required', invalid_isbn: 'Invalid ISBN', invalid_url: 'Invalid URL (http/https)', invalid_date: 'Invalid date',
    invalid_choice: 'Invalid choice', invalid_username: '3–50 characters: letters, digits, . _ -', invalid_email: 'Invalid email address',
    password_too_short: 'At least 8 characters', username_taken: 'Username taken', email_taken: 'Email already registered',
    wrong_password: 'Wrong password', invalid_rating: '1–5 stars', before_start: 'Before start date', compartment_not_found: 'Compartment does not exist',
    at_least_one_column: 'At least one column', too_many_columns: 'Too many columns', too_many_rows: 'Too many compartments',
    duplicate_compartment_name: 'Compartment names must be unique',
  },
  import: { missing_title: 'Row without title', duplicate_in_file: 'Duplicate in file', row_failed: 'Error' },
}

const dictionaries = { de, en }

function initialLocale() {
  try {
    const stored = localStorage.getItem('bookspot.locale')
    if (stored && dictionaries[stored]) return stored
  } catch {
    /* storage unavailable */
  }
  return (navigator.language || 'de').toLowerCase().startsWith('de') ? 'de' : 'en'
}

export const locale = ref(initialLocale())
export const appName = computed(() => dictionaries[locale.value].app.name)

export function setLocale(value) {
  if (!dictionaries[value]) return
  locale.value = value
  document.documentElement.lang = value
  try {
    localStorage.setItem('bookspot.locale', value)
  } catch {
    /* ignore */
  }
}

function lookup(dict, key) {
  return key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), dict)
}

/** t('books.count', { n: 3 }) – falls back to German, then to the key itself. */
export function t(key, params = {}) {
  let text = lookup(dictionaries[locale.value], key) ?? lookup(de, key) ?? key
  if (typeof text !== 'string') return key
  for (const [name, value] of Object.entries(params)) text = text.replaceAll(`{${name}}`, String(value))
  return text
}

/** Human readable message for an ApiError / Error. */
export function errorMessage(error) {
  const code = error?.code ?? error?.message ?? 'request_failed'
  const text = lookup(dictionaries[locale.value], `errors.${code}`) ?? lookup(de, `errors.${code}`)
  return text ?? code
}

/** Field error text from validation code. */
export function fieldError(code) {
  if (!code) return ''
  return lookup(dictionaries[locale.value], `validation.${code}`) ?? code
}
