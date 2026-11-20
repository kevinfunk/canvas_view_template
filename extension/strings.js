/**
 * The panel's translated text.
 *
 * The server sends a table keyed by the English text, with `@name` marking a
 * placeholder. Without the table the English text is shown.
 */
let strings = {};
let langcode = document.documentElement.lang || 'en';

export const setStrings = (table, code) => {
  strings = table ?? {};
  langcode = code || langcode;
};

export const getLangcode = () => langcode;

export const t = (source, args = {}) =>
  (strings[source] ?? source).replace(/@(\w+)/g, (match, name) => (name in args ? String(args[name]) : match));

const SUMMARY_MAX = 3;

export const list = (items) => {
  try {
    return new Intl.ListFormat(langcode, { style: 'long', type: 'conjunction' }).format(items);
  } catch {
    return items.join(', ');
  }
};

/** Names joined for a sentence, the rest counted: "A, B, C and 2 more". */
export const summarize = (labels) => {
  const shown = labels.slice(0, SUMMARY_MAX);
  const more = labels.length - shown.length;
  return list(more > 0 ? [...shown, t('@count more', { count: more })] : shown);
};
