#!/usr/bin/env node
/**
 * validate-engineering-os.mjs
 *
 * Static validation of the engineering operating system:
 *   AGENTS.md, .agents/rules/*.md, .agents/skills/<name>/SKILL.md, docs/engineering/**
 *
 * Checks performed
 *   1. Structure        - expected files and directories exist and are non-empty
 *   2. Skill frontmatter- valid YAML frontmatter per the Agent Skills specification
 *   3. Rule structure   - rule id, canonical owner, checklist section present
 *   4. Registry sync    - AGENTS.md / docs/engineering/README.md registries match disk
 *   5. Duplicates       - duplicate rule ids, skill names, or repeated canonical ownership
 *   6. Links            - relative markdown links and anchors resolve to real targets
 *   7. Contradictions   - mandatory-statement collisions and owner-decision leakage
 *   8. Premature stack  - warns when a rule decides something reserved to the owner
 *
 * Usage:  node .agents/scripts/validate-engineering-os.mjs [--strict]
 *         --strict  treat warnings as failures
 *
 * No dependencies. Spec: ../../docs/engineering/README.md#8-the-validator
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(SCRIPT_DIR, '..', '..');
const STRICT = process.argv.includes('--strict');

const RESULTS = { errors: [], warnings: [], passes: [] };
const error = (check, msg) => RESULTS.errors.push({ check, msg });
const warn = (check, msg) => RESULTS.warnings.push({ check, msg });
const pass = (check, msg) => RESULTS.passes.push({ check, msg });

const rel = (p) => relative(ROOT, p).split(sep).join('/');
const read = (p) => readFileSync(p, 'utf8');

// ---------------------------------------------------------------------------
// 1. Structure
// ---------------------------------------------------------------------------

const AGENTS_MD = join(ROOT, 'AGENTS.md');
const RULES_DIR = join(ROOT, '.agents', 'rules');
const SKILLS_DIR = join(ROOT, '.agents', 'skills');
const DOCS_DIR = join(ROOT, 'docs', 'engineering');

function checkStructure() {
  const required = [
    'README.md',
    'AGENTS.md',
    '.gitignore',
    '.agents/rules',
    '.agents/skills',
    '.agents/scripts/validate-engineering-os.mjs',
    'docs/engineering/README.md',
    'docs/engineering/project-context.md',
    'docs/engineering/templates/report.md',
    'docs/engineering/templates/adr.md',
    'docs/engineering/reports/README.md',
    'docs/engineering/adr/README.md',
  ];
  for (const item of required) {
    const full = join(ROOT, item);
    if (!existsSync(full)) error('structure', `missing required path: ${item}`);
    else if (statSync(full).isFile() && read(full).trim() === '') error('structure', `empty file: ${item}`);
  }
  pass('structure', `${required.length} required paths checked`);
}

// ---------------------------------------------------------------------------
// Collect the governed documents
// ---------------------------------------------------------------------------

function listMarkdown(dir, { recursive = false } = {}) {
  if (!existsSync(dir)) return [];
  const out = [];
  for (const entry of readdirSync(dir).sort()) {
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) {
      if (recursive) out.push(...listMarkdown(full, { recursive }));
    } else if (entry.endsWith('.md')) {
      out.push(full);
    }
  }
  return out;
}

const ruleFiles = listMarkdown(RULES_DIR);
const skillFiles = existsSync(SKILLS_DIR)
  ? readdirSync(SKILLS_DIR)
      .map((d) => join(SKILLS_DIR, d, 'SKILL.md'))
      .filter((p) => existsSync(p))
  : [];
const docFiles = listMarkdown(DOCS_DIR, { recursive: true });
const allDocs = [AGENTS_MD, join(ROOT, 'README.md'), ...ruleFiles, ...skillFiles, ...docFiles].filter((p) =>
  existsSync(p),
);

if (ruleFiles.length === 0) error('structure', 'no rule files found in .agents/rules/');
if (skillFiles.length === 0) error('structure', 'no skill files found in .agents/skills/*/SKILL.md');

// ---------------------------------------------------------------------------
// Frontmatter parsing (minimal YAML subset: scalars and one nested map)
// ---------------------------------------------------------------------------

function parseFrontmatter(text) {
  if (!text.startsWith('---')) return { frontmatter: null, body: text, raw: '' };
  const end = text.indexOf('\n---', 3);
  if (end === -1) return { frontmatter: null, body: text, raw: '' };
  const raw = text.slice(3, end).replace(/^\n/, '');
  const body = text.slice(end + 4).replace(/^\n/, '');
  const frontmatter = {};
  let nestedKey = null;
  for (const line of raw.split('\n')) {
    if (line.trim() === '' || line.trim().startsWith('#')) continue;
    const indented = /^\s+\S/.test(line);
    const kv = line.match(/^\s*([A-Za-z0-9_-]+):\s*(.*)$/);
    if (!kv) continue;
    const [, key, value] = kv;
    const clean = value.replace(/^["']|["']$/g, '').trim();
    if (indented && nestedKey) {
      frontmatter[nestedKey] = { ...(frontmatter[nestedKey] || {}), [key]: clean };
    } else if (clean === '') {
      nestedKey = key;
      frontmatter[key] = {};
    } else {
      nestedKey = null;
      frontmatter[key] = clean;
    }
  }
  return { frontmatter, body, raw };
}

// ---------------------------------------------------------------------------
// 2. Skill frontmatter and content
// ---------------------------------------------------------------------------

const SKILL_NAME_RE = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const skillMeta = [];

function checkSkills() {
  const seen = new Map();
  for (const file of skillFiles) {
    const dirName = file.split(sep).slice(-2)[0];
    const text = read(file);
    const { frontmatter, body } = parseFrontmatter(text);

    if (!frontmatter) {
      error('skill-frontmatter', `${rel(file)}: missing or malformed YAML frontmatter`);
      continue;
    }
    const { name, description } = frontmatter;

    if (!name) error('skill-frontmatter', `${rel(file)}: missing required "name"`);
    else {
      if (!SKILL_NAME_RE.test(name)) {
        error('skill-frontmatter', `${rel(file)}: name "${name}" must be lowercase alphanumeric with single hyphens`);
      }
      if (name.length > 64) error('skill-frontmatter', `${rel(file)}: name exceeds 64 characters`);
      if (name !== dirName) {
        error('skill-frontmatter', `${rel(file)}: name "${name}" must match its directory "${dirName}"`);
      }
      if (seen.has(name)) error('duplicates', `skill name "${name}" declared in ${seen.get(name)} and ${rel(file)}`);
      seen.set(name, rel(file));
    }

    if (!description) error('skill-frontmatter', `${rel(file)}: missing required "description"`);
    else {
      if (description.length > 1024) error('skill-frontmatter', `${rel(file)}: description exceeds 1024 characters`);
      if (description.length < 40) warn('skill-frontmatter', `${rel(file)}: description is very short; it drives skill discovery`);
      if (!/use (it )?when|use when|use whenever|use before|use after/i.test(description)) {
        warn('skill-frontmatter', `${rel(file)}: description does not state when to use the skill`);
      }
    }

    const lineCount = text.split('\n').length;
    if (lineCount > 500) {
      warn('skill-size', `${rel(file)}: ${lineCount} lines; the Agent Skills guidance recommends under 500 - move detail into references/`);
    }
    if (body.trim().length < 400) warn('skill-content', `${rel(file)}: body is very short for an executable workflow`);

    const headings = body.match(/^#{1,3}\s+.*$/gm) || [];
    if (headings.length < 3) error('skill-content', `${rel(file)}: expected at least 3 sections (## ...) in an executable workflow`);
    if (!/(rules\/reporting\.md|reporting rule)/.test(body)) {
      error('skill-content', `${rel(file)}: every workflow must end in a report - no reference to the reporting rule found`);
    }
    if (!/SKILL|workflow/i.test(headings.join(' '))) {
      warn('skill-content', `${rel(file)}: no heading indicates the workflow name`);
    }

    skillMeta.push({ file, name, dirName, text });
  }
  pass('skills', `${skillFiles.length} skill files validated`);
}

// ---------------------------------------------------------------------------
// 3. Rule structure, and 5. duplicates / ownership collisions
// ---------------------------------------------------------------------------

const ruleMeta = [];

function checkRules() {
  const ids = new Map();
  const owners = new Map();
  for (const file of ruleFiles) {
    const text = read(file);
    const idMatch = text.match(/\*\*Rule ID:\*\*\s*`([^`]+)`/);
    if (!idMatch) {
      error('rule-structure', `${rel(file)}: missing "**Rule ID:** \`...\`" declaration`);
      continue;
    }
    const id = idMatch[1];
    const expectedId = file.split(sep).pop().replace(/\.md$/, '').replace(/-fa$/, '');
    if (id !== expectedId) {
      warn('rule-structure', `${rel(file)}: rule id "${id}" does not match filename stem "${expectedId}"`);
    }
    if (ids.has(id)) error('duplicates', `rule id "${id}" declared in ${ids.get(id)} and ${rel(file)}`);
    ids.set(id, rel(file));

    const ownerMatch = text.match(/\*\*Canonical owner of:\*\*\s*([^\n]+)/);
    if (!ownerMatch) {
      error('rule-structure', `${rel(file)}: missing "**Canonical owner of:**" declaration`);
    } else {
      const claimed = ownerMatch[1]
        .split(',')
        .map((s) => s.trim().replace(/\.$/, '').toLowerCase())
        .filter((s) => s.length > 3);
      for (const claim of claimed) {
        if (owners.has(claim)) {
          error(
            'contradictions',
            `ownership collision: "${claim}" is claimed by both ${owners.get(claim)} and ${rel(file)}`,
          );
        } else {
          owners.set(claim, rel(file));
        }
      }
    }

    const h2 = (text.match(/^##\s+.*$/gm) || []).map((h) => h.toLowerCase());
    if (!h2.some((h) => h.includes('checklist'))) {
      error('rule-structure', `${rel(file)}: no "Checklist" section - every rule must end in an executable checklist`);
    }
    const text2 = text.replace(/\s+/g, ' ');
    if (/never|must not|do not/i.test(text) === false) {
      warn('rule-structure', `${rel(file)}: no prohibitions found; rules normally constrain behaviour`);
    }
    if (text.split('\n').length > 260) {
      warn('rule-size', `${rel(file)}: ${text.split('\n').length} lines - consider splitting or trimming`);
    }
    ruleMeta.push({ file, id, text, text2 });
  }
  pass('rules', `${ruleFiles.length} rule files validated`);
}

// ---------------------------------------------------------------------------
// 4. Registry sync (AGENTS.md and docs/engineering/README.md)
// ---------------------------------------------------------------------------

function registryRefs(text) {
  const rules = new Set();
  const skills = new Set();
  for (const m of text.matchAll(/\]\(([^)]*\.agents\/rules\/([a-z0-9-]+)\.md)[^)]*\)/g)) rules.add(m[2]);
  for (const m of text.matchAll(/\]\(([^)]*\.agents\/skills\/([a-z0-9-]+)\/SKILL\.md)[^)]*\)/g)) skills.add(m[2]);
  return { rules, skills };
}

function checkRegistries() {
  const registries = [
    { name: 'AGENTS.md', path: AGENTS_MD },
    { name: 'docs/engineering/README.md', path: join(DOCS_DIR, 'README.md') },
  ];
  const onDiskRules = new Set(ruleFiles.map((f) => f.split(sep).pop().replace(/\.md$/, '')));
  const onDiskSkills = new Set(skillFiles.map((f) => f.split(sep).slice(-2)[0]));

  for (const reg of registries) {
    if (!existsSync(reg.path)) {
      error('registry', `missing registry file: ${reg.name}`);
      continue;
    }
    const { rules, skills } = registryRefs(read(reg.path));
    for (const id of onDiskRules) {
      if (!rules.has(id)) error('registry', `${reg.name}: rule "${id}" exists on disk but is not registered`);
    }
    for (const id of rules) {
      if (!onDiskRules.has(id)) error('registry', `${reg.name}: registers rule "${id}" which does not exist on disk`);
    }
    for (const name of onDiskSkills) {
      if (!skills.has(name)) error('registry', `${reg.name}: skill "${name}" exists on disk but is not registered`);
    }
    for (const name of skills) {
      if (!onDiskSkills.has(name)) error('registry', `${reg.name}: registers skill "${name}" which does not exist on disk`);
    }
  }
  pass('registry', `${ruleFiles.length} rules and ${skillFiles.length} skills cross-checked against 2 registries`);
}

// ---------------------------------------------------------------------------
// 6. Links and anchors
// ---------------------------------------------------------------------------

function slugify(heading) {
  return heading
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s-]/gu, '')
    .replace(/\s/g, "-");
}

const anchorCache = new Map();
function anchorsOf(file) {
  if (anchorCache.has(file)) return anchorCache.get(file);
  const set = new Set();
  for (const line of read(file).split('\n')) {
    const m = line.match(/^#{1,6}\s+(.*)$/);
    if (m) set.add(slugify(m[1]));
  }
  anchorCache.set(file, set);
  return set;
}

function checkLinks() {
  let count = 0;
  for (const file of allDocs) {
    const text = read(file);
    for (const m of text.matchAll(/\[[^\]]*\]\(([^)\s]+)\)/g)) {
      const target = m[1];
      if (/^(https?:|mailto:|#)/.test(target)) {
        if (target.startsWith('#') && !anchorsOf(file).has(target.slice(1))) {
          warn('links', `${rel(file)}: anchor "${target}" not found in the same file`);
        }
        continue;
      }
      count += 1;
      const [pathPart, anchor] = target.split('#');
      const resolved = resolve(dirname(file), pathPart);
      if (!existsSync(resolved)) {
        error('links', `${rel(file)}: broken link -> ${target}`);
        continue;
      }
      if (anchor && resolved.endsWith('.md')) {
        if (!anchorsOf(resolved).has(anchor)) {
          error('links', `${rel(file)}: anchor "#${anchor}" not found in ${rel(resolved)}`);
        }
      }
    }
  }
  pass('links', `${count} relative links resolved across ${allDocs.length} documents`);
}

// ---------------------------------------------------------------------------
// 7. Contradictions between rules
// ---------------------------------------------------------------------------

const MANDATORY_PATTERNS = [
  /must never\s+([^.;]{15,120})/gi,
  /is prohibited\s+([^.;]{15,120})/gi,
  /may not\s+([^.;]{15,120})/gi,
];

function checkContradictions() {
  // (a) the same mandatory statement must not be duplicated verbatim across rule files
  // (duplication is how two documents drift into contradicting each other)
  const statements = new Map();
  for (const { file, text2 } of ruleMeta) {
    for (const re of MANDATORY_PATTERNS) {
      for (const m of text2.matchAll(re)) {
        const norm = m[1].toLowerCase().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, ' ').trim();
        if (norm.length < 20) continue;
        if (!statements.has(norm)) statements.set(norm, new Set());
        statements.get(norm).add(rel(file));
      }
    }
  }
  for (const [statement, files] of statements) {
    if (files.size > 1) {
      warn('contradictions', `duplicated obligation in ${[...files].join(', ')}: "${statement.slice(0, 70)}..."`);
    }
  }

  // (b) document precedence must be declared exactly once with the same order
  const precedenceFiles = ruleMeta.filter(({ text }) => /precedence/i.test(text));
  if (precedenceFiles.length > 1) {
    warn('contradictions', `precedence declared in ${precedenceFiles.map((f) => rel(f.file)).join(', ')}; keep it in AGENTS.md only`);
  }

  // (c) no rule may instruct an agent to decide a reserved open item
  for (const { file, text } of ruleMeta) {
    for (const m of text.matchAll(/\bO-(\d{1,2})\b/g)) {
      const line = text.split('\n').find((l) => l.includes(`O-${m[1]}`)) || '';
      if (!/(ask|open|raised|decision|owner|undecided|not decided)/i.test(line)) {
        warn('contradictions', `${rel(file)}: references O-${m[1]} without stating it is an owner decision`);
      }
    }
  }
  pass('contradictions', `checked ${statements.size} mandatory statements for duplication`);
}

// ---------------------------------------------------------------------------
// 8. Premature stack decisions (must stay owner-reserved)
// ---------------------------------------------------------------------------

const OWNER_RESERVED_TOKENS = [
  'mysql', 'mariadb', 'postgres', 'postgresql', 'sqlite', 'sqlserver',
  'laravel', 'symfony', 'codeigniter', 'yii', 'wordpress',
  'react', 'vue', 'angular', 'svelte', 'jquery', 'tailwind', 'alpinejs',
  'mongodb', 'redis', 'memcached', 'elasticsearch',
  'stripe', 'cloudflare', 'firebase', 'aws', 's3', 'google cloud',
  'docker', 'kubernetes', 'nginx', 'apache',
];

const NEGATION_MARKERS = [
  'not ', 'no ', 'never', 'without', 'avoid', 'must not', 'do not', "don't", 'reserved',
  'undecided', 'unratified', 'forbidden', 'prohibit', 'until', 'engine-specific', 'ask',
  'owner', 'ratified', 'question', 'open decision', 'banned',
  // A provisional statement is acceptable when the text itself says it is provisional.
  'assumption', 'assumed', 'to be confirmed', 'confirm', 'verify', 'unverified', 'provisional',
];

function checkPrematureStack() {
  let flagged = 0;
  for (const file of allDocs) {
    const text = read(file);
    const lines = text.split('\n');
    lines.forEach((line, index) => {
      const lower = line.toLowerCase();
      const hit = OWNER_RESERVED_TOKENS.find((token) => new RegExp(`\\b${token}\\b`).test(lower));
      if (!hit) return;
      // Prose wraps: the qualifier ("an assumption", "to be confirmed", "owner decision") often
      // sits on a neighbouring line. The marker is therefore looked for in a small window, not
      // only on the exact line that names the technology.
      const window = lines
        .slice(Math.max(0, index - 2), index + 3)
        .join(' ')
        .toLowerCase();
      if (NEGATION_MARKERS.some((marker) => window.includes(marker))) return;
      flagged += 1;
      warn(
        'premature-stack',
        `${rel(file)}:${index + 1}: mentions reserved technology "${hit}" as if decided - the owner has not ratified it`,
      );
    });
  }
  if (flagged === 0) {
    pass('premature-stack', `no unratified technology decision found (${OWNER_RESERVED_TOKENS.length} tokens scanned)`);
  }
}

// ---------------------------------------------------------------------------
// Project-context consistency
// ---------------------------------------------------------------------------

function checkProjectContext() {
  const file = join(DOCS_DIR, 'project-context.md');
  if (!existsSync(file)) return;
  const text = read(file);
  const openIds = new Set();
  for (const m of text.matchAll(/\|\s*`(O-\d+)`\s*\|/g)) openIds.add(m[1]);
  if (openIds.size === 0) warn('project-context', 'no open decisions (O-n) recorded; the repository is in bootstrap state');
  const knownIds = new Set([...text.matchAll(/`O-(\d+)`/g)].map((m) => `O-${m[1]}`));
  for (const id of knownIds) {
    if (!openIds.has(id)) warn('project-context', `${id} is referenced but not listed in the open-decision table`);
  }
  for (const constraint of ['C-1', 'C-2', 'C-3', 'C-4', 'C-5']) {
    if (!text.includes(`\`${constraint}\``)) {
      warn('project-context', `owner constraint ${constraint} is not recorded in project-context.md`);
    }
  }
  // Rules must not restate the constraint ledger verbatim; they link to it.
  for (const { file: ruleFile, text: ruleText } of ruleMeta) {
    const listed = ['C-1', 'C-2', 'C-3', 'C-4', 'C-5'].filter((c) => ruleText.includes(`\`${c}\``));
    if (listed.length >= 4) {
      warn('project-context', `${rel(ruleFile)} duplicates the constraint ledger (${listed.join(', ')}); link instead`);
    }
  }
  pass('project-context', `${openIds.size} open decisions and 5 owner constraints recorded`);
}

// ---------------------------------------------------------------------------
// Report templates
// ---------------------------------------------------------------------------

function checkReportTemplate() {
  const tpl = join(DOCS_DIR, 'templates', 'report.md');
  const rule = join(RULES_DIR, 'reporting.md');
  if (!existsSync(tpl) || !existsSync(rule)) {
    error('report-template', 'report template or reporting rule missing');
    return;
  }
  const templateText = read(tpl).toUpperCase();
  const ruleText = read(rule).toUpperCase();
  const sections = ['IMPLEMENTED', 'TESTS EXECUTED', 'RESULTS', 'SECURITY REVIEW', 'PERFORMANCE REVIEW', 'REGRESSION REVIEW', 'REMAINING RISKS'];
  for (const section of sections) {
    if (!templateText.includes(section)) error('report-template', `template is missing section: ${section}`);
    if (!ruleText.includes(section)) error('report-template', `reporting rule is missing section: ${section}`);
  }
  for (const status of ['PASS WITH RISKS', 'BLOCKED', 'FAIL']) {
    if (!templateText.includes(status)) error('report-template', `template is missing status: ${status}`);
    if (!ruleText.includes(status)) error('report-template', `reporting rule is missing status: ${status}`);
  }
  pass('report-template', `${sections.length} report sections and the status vocabulary are consistent`);
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

checkStructure();
checkSkills();
checkRules();
checkRegistries();
checkLinks();
checkContradictions();
checkPrematureStack();
checkProjectContext();
checkReportTemplate();

const out = [];
out.push('');
out.push('ENGINEERING OS VALIDATION');
out.push('='.repeat(60));
for (const p of RESULTS.passes) out.push(`  PASS  [${p.check}] ${p.msg}`);
for (const w of RESULTS.warnings) out.push(`  WARN  [${w.check}] ${w.msg}`);
for (const e of RESULTS.errors) out.push(`  ERROR [${e.check}] ${e.msg}`);
out.push('-'.repeat(60));
out.push(
  `  ${RESULTS.passes.length} checks passed, ${RESULTS.warnings.length} warning(s), ${RESULTS.errors.length} error(s)`,
);
out.push('');

process.stdout.write(out.join('\n'));

const failed = RESULTS.errors.length > 0 || (STRICT && RESULTS.warnings.length > 0);
if (failed) {
  process.stdout.write(`  RESULT: FAIL${STRICT && RESULTS.errors.length === 0 ? ' (strict mode: warnings treated as errors)' : ''}\n\n`);
  process.exit(1);
}
process.stdout.write('  RESULT: PASS\n\n');
process.exit(0);
