#!/usr/bin/env bash

INPUT="$1"

normalize() {
    # Nettoie espaces multiples et autour des points (IP, version)
    sed -E 's/[[:space:]]+/ /g' | sed -E 's/ *\. */./g' | sed -E 's/^ +| +$//g'
}

grep -oP '(HTTPServer|IP|Title|JQuery|PHP|WordPress|X-Powered-By|MetaGenerator)(\[[^]]*\])?' "$INPUT" | while read -r line; do
    if [[ $line =~ ^MetaGenerator\[(.*)\]$ ]]; then
        block="${BASH_REMATCH[1]}"
        # Découpe sur ';' puis ','
        IFS=';,'
        for part in $block; do
            part=$(echo "$part" | normalize)
            # Si version à la fin
            if [[ $part =~ (.+[^0-9])\s*([0-9]+([.][0-9]+)*[a-zA-Z0-9\-\.]*)$ ]]; then
                label=$(echo "${BASH_REMATCH[1]}" | normalize)
                version=$(echo "${BASH_REMATCH[2]}" | normalize)
                echo -e "MetaGenerator $label\t$version"
            fi
        done
        unset IFS
    elif [[ $line =~ ^([A-Za-z0-9\-\+\.]+)\[([^]]+)\]$ ]]; then
        name="${BASH_REMATCH[1]}"
        value=$(echo "${BASH_REMATCH[2]}" | normalize)
        echo -e "$name\t$value"
    else
        echo "$line" | normalize
    fi
done
