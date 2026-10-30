let html5QrcodeScanner = null;
const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

function playBeep() {
    try {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(1200, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.12);
    } catch (e) {
        console.warn("Audio Context beep failed: ", e);
    }
}

function initScanner() {
    const statusPulse = document.getElementById('status-pulse');
    const startBtn = document.getElementById('btn-start-scan');
    const stopBtn = document.getElementById('btn-stop-scan');
    const ocrBtn = document.getElementById('btn-read-text');
    const scanOverlay = document.getElementById('scan-overlay');

    html5QrcodeScanner = new Html5Qrcode("reader");

    startBtn.addEventListener('click', () => {
        html5QrcodeScanner.start(
            { facingMode: "environment" },
            {
                fps: 10,
                qrbox: (width, height) => {
                    return { width: width * 0.8, height: height * 0.3 };
                }
            },
            onScanSuccess,
            onScanFailure
        ).then(() => {
            statusPulse.classList.add('active');
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-flex';
            ocrBtn.style.display = 'inline-flex';
            scanOverlay.style.display = 'block';
        }).catch(err => {
            console.error("Impossibile avviare fotocamera: ", err);
            alert("Errore nell'avvio della fotocamera: " + err);
        });
    });

    stopBtn.addEventListener('click', stopScanner);

    ocrBtn.addEventListener('click', () => {
        captureAndOCR();
    });
}

function stopScanner() {
    const statusPulse = document.getElementById('status-pulse');
    const startBtn = document.getElementById('btn-start-scan');
    const stopBtn = document.getElementById('btn-stop-scan');
    const ocrBtn = document.getElementById('btn-read-text');
    const scanOverlay = document.getElementById('scan-overlay');

    if (html5QrcodeScanner && html5QrcodeScanner.isScanning) {
        html5QrcodeScanner.stop().then(() => {
            statusPulse.classList.remove('active');
            startBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
            ocrBtn.style.display = 'none';
            scanOverlay.style.display = 'none';
        });
    }
}

function onScanSuccess(decodedText, decodedResult) {
    // Rilevamento barcode automatico disattivato su richiesta dell'utente
}

function onScanFailure(error) {
    // Fallimenti di scansione barcode normali
}

function captureAndOCR() {
    const video = document.querySelector("#reader video");
    if (!video) {
        alert("Fotocamera non attiva.");
        return;
    }

    const loader = document.getElementById('ocr-loader');
    const statusText = document.getElementById('ocr-status-text');
    
    loader.style.display = 'flex';
    statusText.innerText = 'Cattura immagine...';

    try {
        // Cattura il frame dal flusso video (invia l'intera immagine per massima affidabilità di lettura)
        const canvas = document.createElement("canvas");
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        const dataUrl = canvas.toDataURL("image/png");

        statusText.innerText = 'Caricamento motore OCR...';

        // Esegui riconoscimento testo tramite Tesseract.js (usando 'eng' per caricamento rapido ed affidabile)
        Tesseract.recognize(
            dataUrl,
            'eng',
            {
                logger: m => {
                    if (m.status === 'recognizing text') {
                        statusText.innerText = `Lettura testo: ${Math.round(m.progress * 100)}%`;
                    }
                }
            }
        ).then(({ data: { text } }) => {
            console.log("Testo estratto OCR: ", text);
            if (!text || text.trim() === "") {
                loader.style.display = 'none';
                alert("Nessun testo rilevato. Riprova inquadrando meglio.");
                return;
            }

            statusText.innerText = 'Ricerca nel database...';
            sendOCRText(text);
        }).catch(err => {
            console.error(err);
            loader.style.display = 'none';
            alert("Errore durante la lettura OCR: " + err.message);
        });

    } catch (e) {
        console.error(e);
        loader.style.display = 'none';
        alert("Errore nella cattura dell'immagine.");
    }
}

function sendOCRText(text) {
    const loader = document.getElementById('ocr-loader');
    
    const formData = new FormData();
    formData.append('text', text);

    fetch('/api/search_ocr.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        loader.style.display = 'none';
        playBeep();
        stopScanner();
        
        if (res.success && res.match) {
            displayResult(res.data, res.data.parsed_civico);
        } else {
            displayNoMatch(res.message || "Nessun indirizzo corrispondente trovato nell'etichetta.");
        }
    })
    .catch(err => {
        console.error(err);
        loader.style.display = 'none';
        displayNoMatch("Errore di connessione durante l'analisi OCR.");
    });
}

function lookupData(queryString) {
    const resultCard = document.getElementById('result-card');
    
    fetch(`/api/search.php?${queryString}`)
        .then(res => res.json())
        .then(res => {
            if (res.success && res.match) {
                displayResult(res.data);
            } else {
                displayNoMatch(res.message || "Indirizzo o codice non associato a nessuna zona.");
            }
        })
        .catch(err => {
            console.error(err);
            displayNoMatch("Errore di connessione al database.");
        });
}

function displayResult(data, parsedCivico = null) {
    const resultCard = document.getElementById('result-card');
    const zoneDisplay = document.getElementById('zone-display');
    const matchVia = document.getElementById('match-via');
    const matchCivicoType = document.getElementById('match-civico-type');
    
    zoneDisplay.innerText = data.zona_sigla;
    if (data.zona_sigla.length > 5) {
        zoneDisplay.classList.add('zone-display-long');
    } else {
        zoneDisplay.classList.remove('zone-display-long');
    }
    
    let viaLabel = `${data.via}, ${data.localita}`;
    if (parsedCivico) {
        viaLabel += ` (Civico rilevato: ${parsedCivico})`;
    }
    matchVia.innerText = viaLabel;
    
    let civicoDetail = '';
    if (data.tipo_civico === 'TUTTI') {
        civicoDetail = 'Tutti i civici';
    } else if (data.tipo_civico === 'PARI') {
        civicoDetail = 'Tutti i civici PARI';
    } else if (data.tipo_civico === 'DISPARI') {
        civicoDetail = 'Tutti i civici DISPARI';
    } else if (data.tipo_civico === 'INTERVALLO') {
        civicoDetail = `Civici da ${data.da_civico} a ${data.a_civico}`;
    }
    
    matchCivicoType.innerText = civicoDetail;
    
    const assignContainer = document.getElementById('assign-zone-container');
    const assignIdInput = document.getElementById('assign-id');
    const assignValueInput = document.getElementById('assign-input');
    
    // Mostra il form di assegnazione rapida se la zona è temporanea (DA ASSEGNARE)
    if (data.zona_sigla === 'DA ASSEGNARE') {
        assignContainer.style.display = 'block';
        assignIdInput.value = data.id;
        assignValueInput.value = '';
    } else {
        assignContainer.style.display = 'none';
    }
    
    resultCard.classList.add('visible');
    resultCard.scrollIntoView({ behavior: 'smooth' });
}

function displayNoMatch(message) {
    const resultCard = document.getElementById('result-card');
    const zoneDisplay = document.getElementById('zone-display');
    const matchVia = document.getElementById('match-via');
    const matchCivicoType = document.getElementById('match-civico-type');
    
    zoneDisplay.innerText = "N.D.";
    zoneDisplay.classList.remove('zone-display-long');
    matchVia.innerText = "Non Assegnato";
    matchCivicoType.innerText = message;
    
    resultCard.classList.add('visible');
    resultCard.scrollIntoView({ behavior: 'smooth' });
}

// Inizializza al caricamento del DOM
document.addEventListener('DOMContentLoaded', () => {
    initScanner();
    
    const viaInput = document.getElementById('search-via');
    const resultsDiv = document.getElementById('autocomplete-results');
    let debounceTimer = null;

    // Gestione autocompletamento in tempo reale con menu a comparsa personalizzato
    viaInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const query = viaInput.value.trim();
        
        if (query.length < 2) {
            resultsDiv.innerHTML = '';
            resultsDiv.style.display = 'none';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch(`/api/suggest.php?q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    if (data.length === 0) {
                        resultsDiv.style.display = 'none';
                        return;
                    }

                    data.forEach(item => {
                        const itemDiv = document.createElement('div');
                        itemDiv.className = 'autocomplete-item';
                        
                        const textSpan = document.createElement('span');
                        textSpan.textContent = item.via;
                        
                        const locSpan = document.createElement('span');
                        locSpan.className = 'item-locality';
                        locSpan.textContent = item.localita;
                        
                        itemDiv.appendChild(textSpan);
                        itemDiv.appendChild(locSpan);
                        
                        // Al click compila l'input nel formato "VIA (COMUNE)" e nasconde la tendina
                        itemDiv.addEventListener('click', () => {
                            viaInput.value = `${item.via} (${item.localita})`;
                            resultsDiv.innerHTML = '';
                            resultsDiv.style.display = 'none';
                        });
                        
                        resultsDiv.appendChild(itemDiv);
                    });
                    resultsDiv.style.display = 'block';
                })
                .catch(err => console.error("Autocomplete error:", err));
        }, 250); // 250ms debounce
    });

    // Chiude la tendina se si clicca all'esterno dell'input
    document.addEventListener('click', (e) => {
        if (e.target !== viaInput && e.target !== resultsDiv) {
            resultsDiv.innerHTML = '';
            resultsDiv.style.display = 'none';
        }
    });

    const searchForm = document.getElementById('search-form');
    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        
        // Estrai il nome pulito della via escludendo la parte del comune tra parentesi
        const viaRaw = viaInput.value.trim();
        const viaClean = viaRaw.replace(/\s*\(.*?\)\s*$/, ''); // Rimuove "(ANGHIARI)", "(AREZZO)" ecc.
        
        const civico = document.getElementById('search-civico').value.trim();
        
        if (viaClean === '') {
            alert('Inserisci il nome di una via.');
            return;
        }
        
        lookupData(`via=${encodeURIComponent(viaClean)}&civico=${encodeURIComponent(civico)}`);
    });

    // Gestione salvataggio rapido zona da assegnare
    const assignForm = document.getElementById('assign-zone-form');
    assignForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const id = document.getElementById('assign-id').value;
        const newZona = document.getElementById('assign-input').value.trim();
        
        if (!newZona) return;

        const formData = new FormData();
        formData.append('id', id);
        formData.append('zona_sigla', newZona);

        fetch('/api/assign_zone.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                playBeep();
                // Ricarica la scheda del risultato aggiornando il valore visualizzato
                // Recuperiamo i dati attualmente visualizzati e aggiorniamo solo la sigla
                const viaLabelElement = document.getElementById('match-via').innerText;
                const civicoDetailElement = document.getElementById('match-civico-type').innerText;
                
                // Aggiorna la visualizzazione a schermo immediata
                const zoneDisplay = document.getElementById('zone-display');
                zoneDisplay.innerText = res.new_zona;
                if (res.new_zona.length > 5) {
                    zoneDisplay.classList.add('zone-display-long');
                } else {
                    zoneDisplay.classList.remove('zone-display-long');
                }
                document.getElementById('assign-zone-container').style.display = 'none';
                
                // Cambia il colore della zona
                const hue = getHueForZone(res.new_zona);
                zoneDisplay.style.background = `linear-gradient(135deg, hsl(${hue}, 85%, 60%) 0%, hsl(${hue + 40}, 90%, 50%) 100%)`;
                zoneDisplay.style.webkitBackgroundClip = 'text';
            } else {
                alert("Errore nel salvataggio: " + res.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert("Errore di connessione durante il salvataggio.");
        });
    });
});

function getHueForZone(zoneName) {
    let hash = 0;
    for (let i = 0; i < zoneName.length; i++) {
        hash = zoneName.charCodeAt(i) + ((hash << 5) - hash);
    }
    return Math.abs(hash % 360);
}
