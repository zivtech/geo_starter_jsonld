#!/usr/bin/env python3
"""Offline schema.org domain validator for emitted JSON-LD.

Reproduces the "property not expected for type" check behind
validator.schema.org, but offline and scriptable — useful when the content is
on a local host the hosted validator cannot fetch. For every typed object in a
JSON-LD document it verifies each property is in the `domainIncludes` of the
object's @type OR any ancestor class (walking rdfs:subClassOf). Range/value-type
warnings are out of scope (the module emits none); this is the domain class only.

This was written to clear the warnings recorded in
docs/SCHEMA-VALIDATION-2026-06-01.md and is kept as the reproducible companion to
that doc and to tools/jsonld-probe.php. It is calibrated: run against the
pre-change emission it reproduces those 8 warnings exactly.

Usage:
    # 1. Fetch the official vocabulary once (~1.5 MB):
    curl -sL https://schema.org/version/latest/schemaorg-current-https.jsonld \\
        -o /tmp/schemaorg.jsonld

    # 2. Extract the emitted @graph from a page and check it:
    curl -sk https://SITE/path | \\
        python3 -c "import sys,re,json;b=re.findall(r'<script type=\"application/ld\\+json\">(.*?)</script>',sys.stdin.read(),re.S);print([x for x in b if '@graph' in x][0])" \\
        > /tmp/page.jsonld
    python3 tools/schema-domain-check.py /tmp/schemaorg.jsonld /tmp/page.jsonld

Exit code is non-zero if any node has an error (unknown property) or warning
(property not in type's domain).
"""
import json
import sys


def aslist(x):
    if x is None:
        return []
    return x if isinstance(x, list) else [x]


def short(idstr):
    if idstr.startswith('schema:'):
        return idstr[len('schema:'):]
    for p in ('https://schema.org/', 'http://schema.org/'):
        if idstr.startswith(p):
            return idstr[len(p):]
    return idstr


def load_vocab(path):
    graph = json.load(open(path))['@graph']
    parents, prop_domains = {}, {}
    for n in graph:
        nid = short(n['@id'])
        types = [short(t) for t in aslist(n.get('@type'))]
        if 'rdfs:Class' in types:
            parents.setdefault(nid, set()).update(
                short(d['@id']) for d in aslist(n.get('rdfs:subClassOf'))
                if isinstance(d, dict) and '@id' in d)
        if 'rdf:Property' in types:
            prop_domains.setdefault(nid, set()).update(
                short(d['@id']) for d in aslist(n.get('schema:domainIncludes'))
                if isinstance(d, dict) and '@id' in d)
    return parents, prop_domains


def ancestors(t, parents):
    seen, stack = set(), [t]
    while stack:
        x = stack.pop()
        if x not in seen:
            seen.add(x)
            stack.extend(parents.get(x, ()))
    return seen


def walk(obj, parents, prop_domains, violations, unknown):
    if isinstance(obj, list):
        for x in obj:
            walk(x, parents, prop_domains, violations, unknown)
        return
    if not isinstance(obj, dict):
        return
    types = [short(t) for t in aslist(obj.get('@type'))]
    for k, v in obj.items():
        if k.startswith('@'):
            if k == '@graph':
                walk(v, parents, prop_domains, violations, unknown)
            continue
        if types:
            prop = short(k)
            doms = prop_domains.get(prop)
            occ = len(v) if isinstance(v, list) else 1
            if doms is None:
                unknown.append(('/'.join(types), prop, occ))
            elif not any(ancestors(t, parents) & doms for t in types):
                violations.append(('/'.join(types), prop, occ))
        walk(v, parents, prop_domains, violations, unknown)


def main(argv):
    if len(argv) < 3:
        sys.exit(__doc__)
    parents, prop_domains = load_vocab(argv[1])
    total_err = total_warn = 0
    for path in argv[2:]:
        violations, unknown = [], []
        walk(json.load(open(path)), parents, prop_domains, violations, unknown)
        occ = sum(o for *_, o in violations)
        total_err += len(unknown)
        total_warn += len(violations)
        flag = '' if not (violations or unknown) else '  <-- ISSUE'
        print(f"{path}: errors={len(unknown)} "
              f"warnings={len(violations)} distinct / {occ} occ{flag}")
        for t, p, o in unknown:
            print(f"    ERR  {t}.{p}" + (f"  (x{o})" if o > 1 else ""))
        for t, p, o in violations:
            print(f"    WARN {t}.{p}" + (f"  (x{o})" if o > 1 else ""))
    print(f"TOTAL: errors={total_err}  warning-properties={total_warn}  "
          f"({len(argv) - 2} file(s))")
    sys.exit(1 if (total_err or total_warn) else 0)


if __name__ == '__main__':
    main(sys.argv)
