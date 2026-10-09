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
    setupHint: 'Das erste Konto wird Administrator. Danach ist die Registrierung nur offen, wenn ALLOW_REGISTRATION=true gesetzt ist.',
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
    newTitle: 'Buch anlegen', editTitle: 'Buch bearbeiten', fetch: 'Daten abrufen', fetching: 'Suche bei Open Library / Google Books …',
    fetched: 'Daten übernommen ({source}).', notFoundOnline: 'Keine Online-Daten gefunden – bitte manuell ausfüllen.', lookupUnavailable: 'Open Library / Google Books sind gerade nicht erreichbar – bitte später erneut versuchen oder manuell ausfüllen.',
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
    pushSent: 'Test gesendet an {n} Gerät(e).', metadataTitle: 'Metadaten ergänzen', metadataHint: 'Fehlende Cover, Beschreibungen, Verlage und Genres bei Open Library / Google Books nachschlagen.',
    metadataRun: 'Jetzt ergänzen', metadataDone: 'Fertig – {updated} Bücher ergänzt.', metadataRemaining: 'Noch {n} Bücher ungeprüft.', metadataUnavailable: 'Metadaten-Dienste nicht erreichbar oder Limit erreicht – später erneut versuchen.',
    users: 'Benutzer', role_admin: 'Admin', role_member: 'Mitglied', about: 'Über', version: 'Version {v}', installApp: 'App installieren',
    sourceCode: 'Open Source (MIT)',
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
    setupHint: 'The first account becomes administrator. Afterwards registration is only open if ALLOW_REGISTRATION=true.',
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
    newTitle: 'Add book', editTitle: 'Edit book', fetch: 'Fetch data', fetching: 'Searching Open Library / Google Books …',
    fetched: 'Data applied ({source}).', notFoundOnline: 'No online data found – please fill in manually.', lookupUnavailable: 'Open Library / Google Books are not reachable right now – try again later or fill in manually.',
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
    pushSent: 'Test sent to {n} device(s).', metadataTitle: 'Fill in metadata', metadataHint: 'Look up missing covers, descriptions, publishers and genres on Open Library / Google Books.',
    metadataRun: 'Fill in now', metadataDone: 'Done – {updated} books updated.', metadataRemaining: '{n} books not checked yet.', metadataUnavailable: 'Metadata services unreachable or rate-limited – try again later.',
    users: 'Users', role_admin: 'Admin', role_member: 'Member', about: 'About', version: 'Version {v}', installApp: 'Install app',
    sourceCode: 'Open source (MIT)',
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
