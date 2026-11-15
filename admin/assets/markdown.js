/*
 * BXADMIN-S — минимальный безопасный Markdown для чата.
 *
 * Сначала экранируется весь текст, затем в него вставляются собственные теги:
 * HTML из ответа модели никогда не попадает в DOM как разметка.
 * Поддержаны: заголовки, списки, таблицы, цитаты, код (строчный и блочный),
 * жирный/курсив/зачёркнутый, ссылки и горизонтальные линии.
 */
(function (window) {
    'use strict';

    var ESCAPES = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

    // Ссылки: только безопасные схемы. javascript:, data: и прочее отбрасываются.
    var SAFE_URL = /^(https?:\/\/|mailto:|\/|#)/i;

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (ch) {
            return ESCAPES[ch];
        });
    }

    function tokenize(store, html) {
        store.push(html);

        return '\u0000' + (store.length - 1) + '\u0000';
    }

    function restore(text, store) {
        return text.replace(/\u0000(\d+)\u0000/g, function (match, index) {
            return store[Number(index)] || '';
        });
    }

    /** Строчная разметка внутри абзаца, ячейки, пункта списка. */
    function inline(raw) {
        var text = escapeHtml(raw);
        var store = [];

        // `код` — до всего остального, чтобы внутри не срабатывала разметка.
        text = text.replace(/`([^`\n]+)`/g, function (match, code) {
            return tokenize(store, '<code>' + code + '</code>');
        });

        // [текст](ссылка); в URL допускаем парные скобки, как в javascript:alert(1)
        text = text.replace(/\[([^\]]+)\]\(([^()\s]+(?:\([^)]*\)[^()\s]*)*)\)/g, function (match, label, url) {
            if (!SAFE_URL.test(url)) {
                return label;
            }

            var external = /^https?:\/\//i.test(url) ? ' target="_blank" rel="noopener noreferrer"' : '';

            return tokenize(store, '<a href="' + url + '"' + external + '>' + label + '</a>');
        });

        // Голые ссылки — после markdown-ссылок, чтобы не портить атрибуты.
        text = text.replace(/(^|[\s(])(https?:\/\/[^\s<)\u0000]+)/g, function (match, prefix, url) {
            return prefix + tokenize(
                store,
                '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>'
            );
        });

        text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
        text = text.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');

        return restore(text, store);
    }

    function codeBlock(language, code) {
        var label = language !== '' ? escapeHtml(language) : 'код';

        return '<div class="bxadmins-code">'
            + '<div class="bxadmins-code-head">'
            + '<span class="bxadmins-code-lang">' + label + '</span>'
            + '<button type="button" class="bxadmins-code-copy">Копировать</button>'
            + '</div>'
            + '<pre><code>' + escapeHtml(code) + '</code></pre>'
            + '</div>';
    }

    function isFence(line) {
        return /^\s{0,3}(```+|~~~+)\s*([A-Za-z0-9_+#.-]*)\s*$/.test(line);
    }

    function isTableStart(lines, index) {
        if (index + 1 >= lines.length) {
            return false;
        }

        if (!/\|/.test(lines[index])) {
            return false;
        }

        return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(lines[index + 1]);
    }

    function isListStart(line) {
        return /^\s*([-*+]|\d+[.)])\s+/.test(line);
    }

    function isBlockStart(lines, index) {
        var line = lines[index];

        return isFence(line)
            || /^\s{0,3}(#{1,6})\s+/.test(line)
            || /^\s{0,3}>/.test(line)
            || isListStart(line)
            || /^\s{0,3}([-*_])\s*(\1\s*){2,}$/.test(line)
            || isTableStart(lines, index);
    }

    function tableCells(line) {
        var trimmed = line.trim().replace(/^\|/, '').replace(/\|$/, '');

        return trimmed.split('|').map(function (cell) {
            return cell.trim();
        });
    }

    function renderTable(lines, start) {
        var header = tableCells(lines[start]);
        var index = start + 2;
        var rows = [];

        while (index < lines.length && /\|/.test(lines[index]) && !/^\s*$/.test(lines[index])) {
            rows.push(tableCells(lines[index]));
            index++;
        }

        var html = '<table><thead><tr>';
        header.forEach(function (cell) {
            html += '<th>' + inline(cell) + '</th>';
        });
        html += '</tr></thead><tbody>';

        rows.forEach(function (row) {
            html += '<tr>';
            header.forEach(function (unused, cellIndex) {
                html += '<td>' + inline(row[cellIndex] || '') + '</td>';
            });
            html += '</tr>';
        });

        html += '</tbody></table>';

        return {html: html, next: index};
    }

    function renderListNodes(nodes) {
        if (!nodes.length) {
            return '';
        }

        var html = '';
        var index = 0;

        while (index < nodes.length) {
            var ordered = nodes[index].ordered;
            var tag = ordered ? 'ol' : 'ul';

            html += '<' + tag + '>';

            while (index < nodes.length && nodes[index].ordered === ordered) {
                var node = nodes[index];
                html += '<li>' + inline(node.text).replace(/\n/g, '<br>');

                if (node.children.length) {
                    html += renderListNodes(node.children);
                }

                html += '</li>';
                index++;
            }

            html += '</' + tag + '>';
        }

        return html;
    }

    function renderList(lines, start) {
        var items = [];
        var index = start;

        while (index < lines.length) {
            var match = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/.exec(lines[index]);

            if (!match) {
                break;
            }

            var indent = match[1].replace(/\t/g, '    ').length;
            var text = match[3];
            index++;

            // Продолжение пункта: строки с отступом, не начинающие новый пункт.
            while (index < lines.length
                && /^\s{2,}\S/.test(lines[index])
                && !isListStart(lines[index])) {
                text += '\n' + lines[index].trim();
                index++;
            }

            items.push({indent: indent, ordered: /\d/.test(match[2]), text: text});
        }

        // Дерево по отступам: вложенные списки становятся детьми пункта.
        var root = {children: []};
        var stack = [{indent: -1, node: root}];

        items.forEach(function (item) {
            while (stack.length > 1 && item.indent <= stack[stack.length - 1].indent) {
                stack.pop();
            }

            var node = {text: item.text, ordered: item.ordered, children: []};
            stack[stack.length - 1].node.children.push(node);
            stack.push({indent: item.indent, node: node});
        });

        return {html: renderListNodes(root.children), next: index};
    }

    function renderBlocks(lines) {
        var out = [];
        var index = 0;
        var total = lines.length;

        while (index < total) {
            var line = lines[index];

            // Блок кода: закрывающий забор необязателен (ответ ещё печатается).
            var fence = /^\s{0,3}(```+|~~~+)\s*([A-Za-z0-9_+#.-]*)\s*$/.exec(line);
            if (fence) {
                var marker = fence[1].charAt(0);
                var length = fence[1].length;
                var body = [];
                index++;

                while (index < total) {
                    var close = /^\s{0,3}(`{3,}|~{3,})\s*$/.exec(lines[index]);
                    if (close && close[1].charAt(0) === marker && close[1].length >= length) {
                        index++;
                        break;
                    }

                    body.push(lines[index]);
                    index++;
                }

                out.push(codeBlock(fence[2], body.join('\n')));
                continue;
            }

            if (/^\s*$/.test(line)) {
                index++;
                continue;
            }

            if (/^\s{0,3}([-*_])\s*(\1\s*){2,}$/.test(line)) {
                out.push('<hr>');
                index++;
                continue;
            }

            var heading = /^\s{0,3}(#{1,6})\s+(.*)$/.exec(line);
            if (heading) {
                var level = heading[1].length;
                var title = heading[2].replace(/\s+#+\s*$/, '');
                out.push('<h' + level + '>' + inline(title) + '</h' + level + '>');
                index++;
                continue;
            }

            if (isTableStart(lines, index)) {
                var table = renderTable(lines, index);
                out.push(table.html);
                index = table.next;
                continue;
            }

            if (/^\s{0,3}>/.test(line)) {
                var quoted = [];

                while (index < total && /^\s{0,3}>/.test(lines[index])) {
                    quoted.push(lines[index].replace(/^\s{0,3}>\s?/, ''));
                    index++;
                }

                out.push('<blockquote>' + renderBlocks(quoted) + '</blockquote>');
                continue;
            }

            if (isListStart(line)) {
                var list = renderList(lines, index);
                out.push(list.html);
                index = list.next;
                continue;
            }

            var paragraph = [];

            while (index < total && !/^\s*$/.test(lines[index]) && !isBlockStart(lines, index)) {
                paragraph.push(lines[index]);
                index++;
            }

            if (paragraph.length === 0) {
                index++;
                continue;
            }

            out.push('<p>' + inline(paragraph.join('\n')).replace(/\n/g, '<br>') + '</p>');
        }

        return out;
    }

    function render(source) {
        if (source === null || source === undefined) {
            return '';
        }

        var lines = String(source).replace(/\r\n?/g, '\n').split('\n');

        return renderBlocks(lines).join('');
    }

    window.BXAdminMarkdown = {render: render, escape: escapeHtml};
})(window);
