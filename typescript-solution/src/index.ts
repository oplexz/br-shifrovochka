// Import the modules
import axios from "axios";
import { readFile, writeFile } from "fs/promises";
import { existsSync } from "fs";

// Use type annotations for arrays and objects
const input: string[] = ["1153241526", "1656335361", "5424251322", "3655516563", "4213633456"];
const dictionary: string[][] = [
    ["А", "В", "Г"],
    ["Е", "И", "К"],
    ["Л", "Н", "О"],
    ["П", "Р", "С"],
    ["Т", "У", "Щ"],
    ["Ь", "Я"]
];

const cacheFilename: string = "cache.json";

// Use async/await instead of callbacks for readability
async function readCache(): Promise<Record<string, string[]>> {
    try {
        return existsSync(cacheFilename) ? JSON.parse(await readFile(cacheFilename, "utf8")) : {};
    } catch (error) {
        console.log(error);
        return {};
    }
}

async function writeCache(mask: string, array: string[]): Promise<void> {
    try {
        const cache = await readCache();
        cache[mask] = array;
        await writeFile(cacheFilename, JSON.stringify(cache));
    } catch (error) {
        console.log(error);
    }
}

async function fetchFromAPI(mask: string): Promise<string[]> {
    let url = `https://anagram.poncy.ru/anagram-decoding.cgi?name=words_by_mask_index&inword=${mask}&answer_type=4&required_letters=&exclude_letters=`;

    console.log(url);

    const { data, status, statusText } = await axios.get(url);

    console.log(`Request status: ${status} ${statusText}`);

    // Use optional chaining and nullish coalescing for safety
    return data?.result ?? [];
}

async function fetchByMask(mask: string): Promise<string[]> {
    const cache = await readCache();
    if (cache[mask]) return cache[mask];

    console.log(`Fetching words using mask "${mask}"`);
    const words = await fetchFromAPI(mask);
    console.log(`Got ${words.length} words`);

    await writeCache(mask, words);

    return words;
}

async function fetchByChar(char: string, length: number): Promise<string[]> {
    return await fetchByMask(`${char}${"*".repeat(length - 1)}`);
}

// A function that solves an encrypted word by matching it with a dictionary
async function solve(input: string): Promise<string | false> {
    console.log(`Trying to solve encrypted word "${input}"`);

    // Check if the input is all digits or not
    const firstRun = /^\d+$/.test(input);

    // Convert the input to an array of digits or characters
    const missingCharacters: (number | string)[] = input
        .split("")
        .map((char) => (/\d/.test(char) ? parseInt(char) : char));

    // Declare an array to store possible words
    let words: string[] = [];

    if (firstRun && typeof missingCharacters[0] === "number") {
        // If the input is all digits, get possible words by the first character and length

        // Get possible characters for the first digit of encrypted word
        const possibleChars: string[] = dictionary[missingCharacters[0] - 1];

        // Fetch possible words by the first character and length
        for (const char of possibleChars) {
            words.push(...(await fetchByChar(char, input.length)));
        }
    } else {
        // If the input is not all digits, get possible words by a mask

        // Replace digits with asterisks to create a mask
        const inputAsMask: string = input.replace(/\d/g, "*");

        // Fetch possible words by the mask
        words.push(...(await fetchByMask(inputAsMask)));
    }

    // Create a regex string from the missing characters
    let regexStr: string = "";
    missingCharacters.forEach((char) => {
        if (typeof char === "string") regexStr += char;
        else regexStr += `[${dictionary[char - 1].join("")}]`;
    });

    // Create a regex object with case-insensitive flag
    const regex: RegExp = new RegExp(regexStr, "i");

    // Filter possible words by matching them with the regex
    words = words.filter((word) => regex.test(word.toLocaleUpperCase()));

    // Check the number of possible words and return accordingly
    if (words.length > 1) {
        console.log(`Multiple words found: ${words.join(", ")}`);
        return false;
    } else if (words.length < 1) {
        console.log("No words found!");
        return false;
    } else {
        console.log(`Word found: ${words[0]}`);
        return words[0];
    }
}

async function bulkSolve(input: string[]) {
    for (let i = 0; i < input.length; i++) {
        const res = await solve(input[i]);

        if (res) input[i] = res;
    }
}

async function findSolvableWord(input: string[]): Promise<{ string: string; index: number }> {
    let outputStr!: string;
    let outputIndex!: number;

    for (let i = 0; i < input.length; i++) {
        const res = await solve(input[i]);

        if (res) {
            outputStr = res;
            outputIndex = i;
            break;
        }
    }

    return { string: outputStr, index: outputIndex };
}

function splitString(str: string): string[] {
    let result: string[] = [];

    const parts = str.split("6");

    for (let i = 0; i < parts.length; i++) {
        for (let j = parts.length; j > i; j--) {
            result.push(parts.slice(i, j).join("6"));
        }
    }

    return result.filter((str) => str.length >= 4);
}

async function solveRemainingShortWords(input: string[]): Promise<string[]> {
    let output: string[] = [];

    for (let i = 0; i < input.length; i++) {
        const str = input[i];

        if (/^[а-я]+$/gi.test(str)) {
            output.push(str);
            continue;
        }

        const possibleWords = splitString(str);
        const foundWord = await findSolvableWord(possibleWords);
        output.push(str.replace(possibleWords[foundWord.index], foundWord.string));
    }

    return output;
}

// A function that flips an array of strings horizontally and vertically
function flipArray(input: string[]) {
    let output: string[] = [];

    for (let i = 0; i < input[0].length; i++) {
        let temp: string = "";

        for (let j = 0; j < input.length; j++) {
            temp += input[j][i];
        }

        output.push(temp);
    }

    return output;
}

// A function that solves a crossword puzzle by using a solver function
async function solveCrossword(input: string[]) {
    console.log("Solving crossword puzzle");

    // Copy the input array
    let encryptedWords: string[] = [...input];

    // Solve the horizontal words
    await bulkSolve(encryptedWords);

    // Flip the array and solve the vertical words
    encryptedWords = flipArray(encryptedWords);
    await bulkSolve(encryptedWords);

    // Flip the array back and solve the remaining short words
    encryptedWords = flipArray(encryptedWords);
    encryptedWords = await solveRemainingShortWords(encryptedWords);

    // Return the final result
    return encryptedWords;
}

// Call the main function
solveCrossword(input).then((arr) => console.log("Crossword solved! Result:", arr.join(", ")));
