# 📯 PosteZone

> **Strumento di Smistamento Zone di Recapito & Riconoscimento Civici (OCR) per Portalettere**

**PosteZone** è un'applicazione web pensata per semplificare e velocizzare il recapito della corrispondenza e dei pacchi postali. Attraverso la fotocamera o la ricerca testuale rapida, riconosce indirizzi e numeri civici associando istantaneamente la zona/frazione di recapito assegnata al portalettere.

---

## ✨ Funzionalità Principali

* **📷 Scanner & OCR Istantaneo:** Riconoscimento automatico degli indirizzi da buste e pacchi tramite la fotocamera del dispositivo (smartphone o webcam) con libreria `Tesseract.js`.
* **🔍 Ricerca Stradario con Regole Civici:** Supporta regole avanzate di suddivisione delle vie per:
  - `TUTTI`: Tutti i civici della via appartengono alla stessa zona.
  - `PARI`: Solo i numeri civici pari.
  - `DISPARI`: Solo i numeri civici dispari.
  - `INTERVALLO`: Range specifico (es. dal civico 1 al 100).
* **📊 Gestione Anomalie (Vie Incongruenti):** Monitora gli indirizzi segnalati nei file Excel o dalle spedizioni che richiedono verifica o abbinamento a vie ufficiali.
* **📂 Importazione Stradari:** Supporto per il caricamento e l'aggiornamento massivo dello stradario da file Excel (`.xlsx`) o CSV.
* **⚡ Database SQLite Integrato:** Nessuna dipendenza complessa da database esterni; tutto è memorizzato in un unico file SQLite protetto e performante.

---

## 🚀 Avvio con Docker

Il progetto è completamente containerizzato e pronto all'uso con Docker e Docker Compose:

```bash
# Clona il repository
git clone https://github.com/shadowkrad/PosteZone.git
cd PosteZone

# Avvia il container in background
docker compose up -d --build
```

L'applicazione sarà accessibile all'indirizzo:
* **Web App:** [http://localhost:8089](http://localhost:8089)
* **Pannello Admin / Stradario:** [http://localhost:8089/admin.html](http://localhost:8089/admin.html)

---

## 📁 Struttura del Progetto

```
PosteZone/
├── data/
│   └── postezone.db        # Database SQLite pre-popolato
├── docker/
│   ├── entrypoint.sh       # Script di inizializzazione permessi e database
│   └── nginx.conf          # Configurazione Nginx per Alpine Linux
├── public/
│   ├── api/
│   │   ├── db.php                  # Connessione PDO a SQLite
│   │   ├── search.php              # Ricerca via e civico
│   │   ├── search_ocr.php          # Parsing testo riconosciuto da OCR
│   │   ├── suggest.php             # Autocompletamento rapido
│   │   ├── admin_search.php        # Ricerca per pannello admin
│   │   ├── admin_unmatched.php     # Gestione vie incongruenti
│   │   ├── admin_update_street.php # Aggiornamento regole civici e zone
│   │   ├── assign_zone.php         # Assegnazione zona rapida
│   │   └── import.php              # Importatore CSV
│   ├── css/
│   │   └── style.css               # Tema responsive con supporto mobile
│   ├── js/
│   │   └── app.js                  # Logica frontend, camera e OCR
│   ├── admin.html                  # Dashboard di gestione stradario
│   ├── index.html                  # Interfaccia scanner e ricerca principale
│   └── import_excel_zones.php      # Script di importazione Excel
├── docker-compose.yml
├── Dockerfile
└── README.md
```

---

## 🛠️ Tecnologie Utilizzate

* **Frontend:** HTML5, CSS3 moderno, Vanilla JavaScript, Tesseract.js (OCR), html5-qrcode (Barcode Scanner).
* **Backend:** PHP 8.3 FPM su Alpine Linux.
* **Web Server:** Nginx.
* **Database:** SQLite 3 (WAL mode).
