<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Credit Card Form</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            text-align: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0px 0px 10px rgba(0,0,0,0.1);
            width: 300px;
            margin: auto;
        }
        input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        button {
            width: 100%;
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background: #218838;
        }
    </style>
</head>
<body>

    <div class="container">
        <h2>Enter Credit Card Details</h2>
        <form action="store.php" method="POST">
            <input type="text" name="card_number" placeholder="Card Number" required>
            <input type="text" name="card_holder" placeholder="Card Holder Name" required>
            <input type="text" name="expiry_date" placeholder="Expiry Date (MM/YY)" required>
            <input type="password" name="cvv" placeholder="CVV" required>
            <button type="submit">Save Card</button>
        </form>
    </div>

</body>
</html>
