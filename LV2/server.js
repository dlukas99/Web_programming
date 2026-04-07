const express = require('express');
const fs = require('fs');
const path = require('path');
const app = express();

const PORT = process.env.PORT || 3000;

// Postavljanje EJS-a
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Služi datoteke iz 'public' mape (CSS, slike)
app.use(express.static('public'));

// RUTA 1: Početna (index.html)
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// RUTA 2: Dinamička galerija (/slike)
app.get('/slike', (req, res) => {
    const imagesPath = path.join(__dirname, 'public', 'images');

    // Čitamo sve datoteke iz mape public/images
    fs.readdir(imagesPath, (err, files) => {
        if (err) {
            return res.status(500).send("Greška: Ne mogu pronaći mapu sa slikama.");
        }

        // Filtriramo samo slike (jpg, png, jpeg, svg)
        const images = files
            .filter(file => /\.(jpg|jpeg|png|gif|svg)$/i.test(file))
            .map((file, index) => ({
                url: `/images/${file}`,
                id: `slika${index + 1}`,
                title: `Slika ${index + 1}`
            }));

        // Šaljemo podatke u slike.ejs predložak
        res.render('slike', { images });
    });
});

app.listen(PORT, () => {
    console.log(`Server radi na http://localhost:${PORT}`);
});