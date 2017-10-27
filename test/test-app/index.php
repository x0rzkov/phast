<?php require_once __DIR__ . '/../../src/html-filters.php';?>
<!DOCTYPE html>
<html>
    <head>
        <link rel="stylesheet" href="css/styles.css">
        <script src="js/main.js"></script>
    </head>
    <body>
        <script>
            console.log('Loaded another one!');
        </script>
        <div>This is where we are!</div>
        <img src="wow.jpg" width="100" height="100">
        <img src="images/basset.png">
        <img src="images/basset.png" width="130" height="155">
        <img src="images/batman.jpg">
        <img src="images/batman.jpg" width="84" height="50">
        <img src="https://www.commitstrip.com/wp-content/uploads/2017/09/Strip-Lenfance-du-codeur-Le-piratage-650-finalenglish.jpg">
        <script>
            document.querySelectorAll('img').forEach(i => { i.removeAttribute('width'); i.removeAttribute('height'); });
        </script>
    </body>
</html>
