const fs = require('fs');
const path = require('path');

const TARGET_DIR = path.resolve(__dirname, '../src/pages/education');
const IGNORE_PATTERNS = [
    /^{{.*}}$/, // Vue interpolations
    /^[\s\d\W_]+$/, // Whitespace, numbers, symbols only
    /^&[a-z]+;$/, // HTML entities
    /^[-—•·|]+$/, // Separators
];

function scanDirectory(dir) {
    if (!fs.existsSync(dir)) {
        console.error(`Directory not found: ${dir}`);
        return;
    }

    const files = fs.readdirSync(dir);

    files.forEach(file => {
        const fullPath = path.join(dir, file);
        const stat = fs.statSync(fullPath);

        if (stat.isDirectory()) {
            scanDirectory(fullPath);
        } else if (file.endsWith('.vue')) {
            checkFile(fullPath);
        }
    });
}

function checkFile(filePath) {
    const content = fs.readFileSync(filePath, 'utf-8');
    const lines = content.split('\n');
    
    // Simple regex to capture text between tags: >TEXT<
    // This is naive but works for finding obvious hardcoded strings in templates
    const templateMatch = content.match(/<template>([\s\S]*?)<\/template>/);
    if (!templateMatch) return;

    const templateContent = templateMatch[1];
    
    // Reset regex state if needed, but here we just iterate matches
    // Match text content between > and <
    const regex = />([^<]+)</g;
    let match;

    while ((match = regex.exec(templateContent)) !== null) {
        const text = match[1].trim();
        
        if (!text) continue;
        if (shouldIgnore(text)) continue;

        // Find line number (approximate, searches from start of file)
        const lineNum = getLineNumber(content, match[1], match.index);
        
        // Output relative path
        const relativePath = path.relative(path.join(__dirname, '..'), filePath);
        console.warn(`[Hardcoded] ${relativePath}:${lineNum} -> "${text}"`);
    }
}

function shouldIgnore(text) {
    return IGNORE_PATTERNS.some(pattern => pattern.test(text));
}

function getLineNumber(content, text, indexInTemplate) {
    // This is a rough estimation. For exact usage, we'd need the offset in the full file.
    // However, since we matched on template content, let's just search for the unique string 
    // context or just the line count up to that index if we had the full index.
    // Since regex execution above was on templateContent, index is relative to template start.
    // To keep it simple and lightweight: find the line in the full content that contains this exact text context.
    
    // Better approach: calculate lines up to the match index
    // match.index is relative to templateContent.
    // We need offset of <template> tag.
    const templateStart = content.indexOf('<template>');
    const absoluteIndex = templateStart + "<template>".length + indexInTemplate; // approximate
    
    // This isn't perfect because regex.exec on templateContent doesn't account for the removed <template> tag position perfectly if there are multiple, but good enough for 99% of Vue SFCs
    const preMatch = content.substring(0, absoluteIndex);
    return preMatch.split('\n').length;
}

console.log('Scanning for hardcoded strings in:', TARGET_DIR);
scanDirectory(TARGET_DIR);
