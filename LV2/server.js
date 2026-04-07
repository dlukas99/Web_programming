const express = require('express');
const app = express();

// Railway dinamički dodjeljuje port, pa koristimo process.env.PORT.
// Ako pokrećeš lokalno, koristit će port 3000.
const PORT = process.env.PORT || 3000;

// Posluživanje statičkih datoteka (HTML, CSS, slike) iz mape 'public'
app.use(express.static('public'));

// Glavna ruta - prikazuje index.html automatski zbog express.static.
app.get('/', (req, res) => {
    // Ova poruka se šalje samo ako Express ne pronađe index.html u mapi public.
    res.send('Pozdrav sa Railway servera! (Ako vidiš ovo, provjeri index.html)');
});

// Pokretanje servera na definiranom portu
app.listen(PORT, () => {
    console.log(`Server pokrenut na portu ${PORT}`);
});