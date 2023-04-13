<?php

require_once dirname(__DIR__) . "/vendor/autoload.php";

use Predis\Client;

$client = new Client("tcp://{$_ENV["REDIS_HOST"]}:{$_ENV["REDIS_PORT"]}");

// $client = RedisAdapter::createConnection("redis://{$_ENV["REDIS_HOST"]}:{$_ENV["REDIS_PORT"]}");
// $cache = new RedisAdapter($client);

function fetchByMask(string $mask)
{
    global $client;

    $key = sprintf("poncy-response;%s", preg_replace("/\*/", "-", $mask));

    $value = $client->get($key);

    if ($value) {
        return json_decode($value, true);
    }

    // Create a URL with the mask as a parameter
    $url = "https://anagram.poncy.ru/anagram-decoding.cgi?name=words_by_mask_index&inword=$mask&answer_type=4&required_letters=&exclude_letters=";

    // Use curl to make a GET request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $data = curl_exec($ch);

    if (!curl_errno($ch)) {
        switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200: # OK
                break;
            default:
                echo "Unexpected HTTP code while requesting API: " . $http_code . "</br>";
        }
    }

    curl_close($ch);

    // Decode the JSON data
    $data = json_decode($data, true)["result"];

    $client->set($key, json_encode($data));

    // Use null coalescing for safety
    return $data;
}

function fetchByChar($char, $length)
{
    return fetchByMask($char . str_repeat("*", $length - 1));
}

function filterWords(array $words, array $missingCharacters, array $dict)
{
    // Create a regex string from the missing characters
    $regexStr = "";

    foreach ($missingCharacters as $char) {
        if (is_numeric($char)) {
            $regexStr .= "[" . implode("", $dict[$char - 1]) . "]";
        } else {
            $regexStr .= $char;
        }
    }

    // Create a regex object with case-insensitive flag
    $regex = "/$regexStr/iu";

    // Filter possible words by matching them with the regex
    return array_filter($words, function ($word) use ($regex) {
        return preg_match($regex, strtoupper($word));
    });
}

function solveWord(string $mask, array $dict): string
{
    global $client;

    // redis-cli keys "solved-word;*" | xargs redis-cli del

    $key = sprintf("solved-word;%s", preg_replace("/\*/", "-", $mask));

    $value = $client->get($key);

    if ($value) {
        return $value;
    }

    $input = $mask;

    $firstRun = preg_match("/^\d+$/", $input);

    $missingCharacters = mb_str_split($input);
    $firstChar = $missingCharacters[0];

    $words = [];

    if (is_numeric($firstChar)) {
        $possibleChars = $dict[$firstChar - 1];

        foreach ($possibleChars as $char) {
            $words = array_merge($words, fetchByChar($char, mb_strlen($mask)));
            $words = filterWords($words, $missingCharacters, $dict);

            if (count($words) > 0) {
                break;
            }
        }
    } else {
        $words = array_merge($words, fetchByChar($firstChar, mb_strlen($mask)));
        $words = filterWords($words, $missingCharacters, $dict);
    }

    // Check the number of possible words and return accordingly
    if (count($words) > 1) {
        return $mask;
    } elseif (count($words) < 1) {
        $client->set($key, $mask);
        return $mask;
    } else {
        $client->set($key, reset($words));
        return reset($words);
    }
}

function bulkSolve(array $words, array $dict)
{
    $output = [];

    foreach ($words as $word) {
        array_push($output, solveWord($word, $dict));
    }

    return $output;
}

function flipArray(array $input)
{
    $output = [];

    for ($i = 0; $i < mb_strlen($input[0]); $i++) {
        $temp = "";

        for ($j = 0; $j < count($input); $j++) {
            $temp .= mb_substr($input[$j], $i, 1);
        }

        array_push($output, $temp);
    }

    return $output;
}

function splitString($str)
{
    $result = [];

    $parts = explode("6", $str);

    for ($i = 0; $i < count($parts); $i++) {
        for ($j = count($parts); $j > $i; $j--) {
            $temp = implode("6", array_slice($parts, $i, $j - $i));

            if (mb_strlen($temp) >= 4) {
                array_push($result, $temp);
            }
        }
    }

    return $result;
}

function findSolvableWord(array $input, array $dict)
{
    $outputStr = null;
    $outputIndex = null;

    for ($i = count($input) - 1; $i >= 0; $i--) {
        $res = solveWord($input[$i], $dict);

        if (preg_match("/^[А-Я]+$/imu", $res)) {
            $outputStr = $res;
            $outputIndex = $i;
            break;
        }
    }

    return ["string" => $outputStr, "index" => $outputIndex];
}

function solveRemainingShortWords(array $input, array $dict)
{
    $output = [];

    for ($i = 0; $i < count($input); $i++) {
        $str = $input[$i];

        if (preg_match("/^[А-Я]+$/ium", $str)) {
            array_push($output, $str);
            continue;
        }

        $possibleWords = splitString($str);
        $foundWord = findSolvableWord($possibleWords, $dict);
        array_push($output, str_replace($possibleWords[$foundWord["index"]], $foundWord["string"], $str));
    }

    return $output;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $words = json_decode($_POST["words"]);
    $dict = json_decode($_POST["dict"]);

    $words = bulkSolve($words, $dict);
    $words = flipArray($words);
    $words = bulkSolve($words, $dict);
    $words = flipArray($words);
    $words = solveRemainingShortWords($words, $dict);
}
?>

<!DOCTYPE HTML>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crossword Solver</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-Zenh87qX5JnK2Jl0vWa8Ck2rdkQ2Bzep5IDxbcnCeuOxjzrPF/et3URy9Bv1WTRi" crossorigin="anonymous">

    <style>
        .nav-scroller .nav {
            display: flex;
            flex-wrap: nowrap;
            padding-bottom: 1rem;
            margin-top: -1px;
            overflow-x: auto;
            text-align: center;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
        }

        @media (min-width: 992px) {
            .rounded-lg-3 {
                border-radius: .3rem;
            }
        }

        table {
            text-align: center;
        }

        td {
            width: 40px;
            height: 40px;
        }

        td input {
            width: 40px;
            height: 40px;
            text-align: center;
            font-size: 14px;
            text-transform: uppercase;
        }

        .block {
            background-color: #000;
        }
    </style>
    <script>
        function generatePOSTFields() {
            let dict = [], words = [];

            for(let i = 1; i <= 6; i++) {
                let temp = [];
                for(let j = 1; j <= 3; j++) {
                    temp.push(document.querySelector(`[name="dict${3*(i-1)+j}"][type="text"]`).value);
                }
                dict.push(temp);
            }

            for(let i = 1; i <= 5; i++) {
                words.push(document.querySelector(`[name="word${i}"][type="text"]`).value);
            }

            document.querySelector(`[name="dict"][type="hidden"]`).value = JSON.stringify(dict);
            document.querySelector(`[name="words"][type="hidden"]`).value = JSON.stringify(words);
        }
    </script>
</head>
<body>
<div class="bg-dark text-secondary px-4 py-5 text-center" style="height: 100vh">
    <div class="py-5">
        <h1 class="display-5 fw-bold text-white">Crossword Solver</h1>
        <?php if ($_SERVER["REQUEST_METHOD"] === "POST"): ?>
            <table class="my-4" style="float:none; margin:auto;">
            <?php foreach ($words as $word): ?>
                <tr>
                <?php foreach (mb_str_split($word) as $char): ?>
                    <td class="block">
                        <?php if ($char != "6"): ?>
                        <input type="text" maxlength="1" value="<?php echo $char; ?>">
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </table>
            <a class="btn btn-primary" href="/" role="button">Go back</a>
        <?php else: ?>
            <p class="fs-5 mb-4">Enter your input and get a solved crossword!</p>
            <div class="col-lg-2 mx-auto d-grid gap-3">
                <div class="row g-1">
                    <div class="input-group">
                        <span class="input-group-text">1</span>
                        <input type="text" name="dict1" class="form-control" value="А" />
                        <input type="text" name="dict2" class="form-control" value="В" />
                        <input type="text" name="dict3" class="form-control" value="Г" />
                    </div>
                    
                    <div class="input-group">
                        <span class="input-group-text">2</span>
                        <input type="text" name="dict4" class="form-control" value="Е" />
                        <input type="text" name="dict5" class="form-control" value="И" />
                        <input type="text" name="dict6" class="form-control" value="К" />
                    </div>
                    
                    <div class="input-group">
                        <span class="input-group-text">3</span>
                        <input type="text" name="dict7" class="form-control" value="Л" />
                        <input type="text" name="dict8" class="form-control" value="Н" />
                        <input type="text" name="dict9" class="form-control" value="О" />
                    </div>
                    
                    <div class="input-group">
                        <span class="input-group-text">4</span>
                        <input type="text" name="dict10" class="form-control" value="П" />
                        <input type="text" name="dict11" class="form-control" value="Р" />
                        <input type="text" name="dict12" class="form-control" value="С" />
                    </div>
                    
                    <div class="input-group">
                        <span class="input-group-text">5</span>
                        <input type="text" name="dict13" class="form-control" value="Т" />
                        <input type="text" name="dict14" class="form-control" value="У" />
                        <input type="text" name="dict15" class="form-control" value="Щ" />
                    </div>
                    
                    <div class="input-group">
                        <span class="input-group-text">6</span>
                        <input type="text" name="dict16" class="form-control" value="Ь" />
                        <input type="text" name="dict17" class="form-control" value="Я" />
                        <input type="text" name="dict18" class="form-control" value="" />
                    </div>
                </div>

                <div class="row g-1">
                    <div class="input-group">
                        <span class="input-group-text">Word 1</span>
                        <input type="text" name="word1" class="form-control" value="1153241526" minlength="10"/>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text">Word 2</span>
                        <input type="text" name="word2" class="form-control" value="1656335361" minlength="10"/>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text">Word 3</span>
                        <input type="text" name="word3" class="form-control" value="5424251322" minlength="10"/>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text">Word 4</span>
                        <input type="text" name="word4" class="form-control" value="3655516563" minlength="10"/>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text">Word 5</span>
                        <input type="text" name="word5" class="form-control" value="4213633456" minlength="10"/>
                    </div>
                </div>

                <form method="post" autocomplete="off" onsubmit="generatePOSTFields()">
                    <div class="row g-3">
                        <input name="dict" type="hidden">
                        <input name="words" type="hidden">
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-info" type="submit">Solve</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
