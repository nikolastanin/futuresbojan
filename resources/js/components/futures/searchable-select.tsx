import { useEffect, useRef, useState } from 'react';
import { ChevronDown, Search, X } from 'lucide-react';

interface Props {
    value: string;
    options: string[];
    onChange: (value: string) => void;
    className?: string;
}

export function SearchableSelect({ value, options, onChange, className = '' }: Props) {
    const [open, setOpen]       = useState(false);
    const [query, setQuery]     = useState('');
    const containerRef          = useRef<HTMLDivElement>(null);
    const inputRef              = useRef<HTMLInputElement>(null);

    const filtered = query.trim()
        ? options.filter(o => o.toLowerCase().includes(query.toLowerCase().replace('/', '_')))
        : options;

    // Close on outside click
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
                setQuery('');
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    // Focus input when opened
    useEffect(() => {
        if (open) setTimeout(() => inputRef.current?.focus(), 10);
    }, [open]);

    const select = (opt: string) => {
        onChange(opt);
        setOpen(false);
        setQuery('');
    };

    const displayValue = value.replace('_USDT', '/USDT');

    return (
        <div ref={containerRef} className={`relative ${className}`}>
            {/* Trigger */}
            <button
                type="button"
                onClick={() => setOpen(o => !o)}
                className="flex h-8 w-full items-center justify-between gap-1 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm transition-colors hover:bg-accent focus:outline-none focus:ring-1 focus:ring-ring"
            >
                <span className="font-medium">{displayValue}</span>
                <ChevronDown className={`size-3.5 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {/* Dropdown */}
            {open && (
                <div className="absolute left-0 top-full z-50 mt-1 w-52 rounded-md border border-border bg-popover shadow-lg">
                    {/* Search input */}
                    <div className="flex items-center gap-2 border-b border-border px-3 py-2">
                        <Search className="size-3.5 shrink-0 text-muted-foreground" />
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            placeholder="Search coin…"
                            className="w-full bg-transparent text-sm text-foreground placeholder:text-muted-foreground focus:outline-none"
                        />
                        {query && (
                            <button onClick={() => setQuery('')}>
                                <X className="size-3.5 text-muted-foreground hover:text-foreground" />
                            </button>
                        )}
                    </div>

                    {/* Options list */}
                    <ul className="max-h-56 overflow-y-auto py-1">
                        {filtered.length === 0 ? (
                            <li className="px-3 py-2 text-sm text-muted-foreground">No results</li>
                        ) : (
                            filtered.map(opt => (
                                <li
                                    key={opt}
                                    onMouseDown={() => select(opt)}
                                    className={`cursor-pointer px-3 py-1.5 text-sm transition-colors hover:bg-accent hover:text-accent-foreground ${opt === value ? 'bg-accent/60 font-medium text-accent-foreground' : 'text-foreground'}`}
                                >
                                    {opt.replace('_USDT', '/USDT')}
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}
