import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const cssPath = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  "..",
  "assets",
  "css",
  "price-assistant-account.css"
);
const cssSource = fs.readFileSync(cssPath, "utf8");

function declarationBlock(selector) {
  const ruleStart = cssSource.indexOf(selector + " {");
  assert.notEqual(ruleStart, -1, selector + " rule must exist");
  const blockStart = cssSource.indexOf("{", ruleStart);
  const blockEnd = cssSource.indexOf("}", blockStart);
  assert.notEqual(blockStart, -1, selector + " rule must open");
  assert.notEqual(blockEnd, -1, selector + " rule must close");
  return cssSource.slice(blockStart + 1, blockEnd);
}

function assertDeclaration(block, property, value) {
  assert.ok(
    block.includes(property + ": " + value + ";"),
    property + " must be " + value
  );
}

function assertNoDeclaration(block, property) {
  assert.equal(
    block.includes(property + ":"),
    false,
    property + " must not be declared"
  );
}

const itemBlock = declarationBlock(".cashback-price-assistant__item");
assertDeclaration(itemBlock, "padding", "8px");

const deleteCardBlock = declarationBlock(".cashback-price-assistant__delete-card");
assertDeclaration(deleteCardBlock, "left", "auto");
assertDeclaration(deleteCardBlock, "padding", "0");
assertDeclaration(deleteCardBlock, "position", "absolute !important");
assertDeclaration(deleteCardBlock, "right", "5px");
assertDeclaration(deleteCardBlock, "top", "5px");
assertNoDeclaration(deleteCardBlock, "inset-inline-end");
assertNoDeclaration(deleteCardBlock, "inset-inline-start");
