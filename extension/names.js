/**
 * Pure helpers for template names, ids, sorting and filtering. No DOM.
 */

// Same rule as the server's.
export const MACHINE_NAME = /^[a-z][a-z0-9_]{0,63}$/;
export const MACHINE_NAME_MAX = 64;
// Leaves room for a `_NN` suffix on a copy's id.
const COPY_BASE_MAX = MACHINE_NAME_MAX - 4;

/** A machine name from a label. Labels with no Latin letters give "template". */
export const slug = (label) =>
  label
    // Drops accents, so "Café" becomes "cafe" rather than "caf".
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    // Machine names must start with a letter.
    .replace(/^(\d)/, 't_$1')
    .slice(0, MACHINE_NAME_MAX) || 'template';

/** The first free id for a copy: the label's slug, then `_2`, `_3`... */
export const freeId = (label, taken) => {
  const stem = slug(label);
  let id = stem;
  for (let n = 2; taken.has(id); n++) {
    id = `${stem.slice(0, COPY_BASE_MAX)}_${n}`;
  }
  return id;
};

/** Templates by label (or id), in the user's language, numbers in order. */
export const sortTemplates = (templates, langcode) => {
  const collator = new Intl.Collator(langcode, { sensitivity: 'base', numeric: true });
  return [...templates].sort((a, b) => collator.compare(a.label || a.id, b.label || b.id));
};

/** Whether a template's label, id or description contains the text. */
export const matchesQuery = (template, query) => {
  const needle = query.trim().toLowerCase();
  return (
    needle === '' ||
    [template.label, template.id, template.description].some((value) => (value ?? '').toLowerCase().includes(needle))
  );
};
