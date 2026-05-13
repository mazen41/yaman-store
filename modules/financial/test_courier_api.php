<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Courier API</title>
</head>
<body>
    <h1>Test Courier API</h1>
    <button onclick="testAPI()">Test API</button>
    <pre id="result"></pre>
    
    <script>
    async function testAPI() {
        const result = document.getElementById('result');
        result.textContent = 'Testing...';
        
        try {
            const response = await fetch('api/manage_courier.php?action=list_couriers');
            const text = await response.text();
            result.textContent = 'Status: ' + response.status + '\n\n' + text;
            
            try {
                const data = JSON.parse(text);
                result.textContent += '\n\nParsed:\n' + JSON.stringify(data, null, 2);
            } catch (e) {
                result.textContent += '\n\nJSON Parse Error: ' + e.message;
            }
        } catch (error) {
            result.textContent = 'Error: ' + error.message;
        }
    }
    </script>
</body>
</html>
