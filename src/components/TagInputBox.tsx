import React, { useState, useRef, useEffect, KeyboardEvent } from "react";
import "./TagInputBox.css";

export interface Option {
  id: number;
  name: string;
}

interface Props {
  label: string;
  options: Option[];
  value: Option[];
  onChange: (selected: Option[]) => void;
  placeholder?: string;
}

export default function TagInputBox({
  label,
  options,
  value,
  onChange,
  placeholder = "検索...",
}: Props) {
  const [inputValue, setInputValue] = useState("");
  const [isOpen, setIsOpen] = useState(false);
  const [focusedIndex, setFocusedIndex] = useState(-1);
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const selectedIds = new Set(value.map((v) => v.id));

  const filtered = options.filter(
    (opt) =>
      !selectedIds.has(opt.id) &&
      `${opt.id}:${opt.name}`.toLowerCase().includes(inputValue.toLowerCase())
  );

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
        setFocusedIndex(-1);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  function selectOption(opt: Option) {
    onChange([...value, opt]);
    setInputValue("");
    setFocusedIndex(-1);
    inputRef.current?.focus();
  }

  function removeOption(id: number) {
    onChange(value.filter((v) => v.id !== id));
  }

  function handleKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setFocusedIndex((i) => Math.min(i + 1, filtered.length - 1));
      setIsOpen(true);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setFocusedIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === "Enter" && focusedIndex >= 0) {
      e.preventDefault();
      selectOption(filtered[focusedIndex]);
    } else if (e.key === "Escape") {
      setIsOpen(false);
      setFocusedIndex(-1);
    } else if (e.key === "Backspace" && inputValue === "" && value.length > 0) {
      removeOption(value[value.length - 1].id);
    }
  }

  return (
    <div className="tag-input-row">
      <label className="tag-input-label">{label}</label>
      <div className="tag-input-wrapper" ref={containerRef}>
        <div
          className="tag-input-box"
          onClick={() => {
            inputRef.current?.focus();
            setIsOpen(true);
          }}
        >
          {value.map((opt) => (
            <span key={opt.id} className="tag">
              <span className="tag-label">{opt.id}:{opt.name}</span>
              <button
                className="tag-remove"
                onMouseDown={(e) => {
                  e.preventDefault();
                  removeOption(opt.id);
                }}
                aria-label={`${opt.name}を削除`}
              >
                ×
              </button>
            </span>
          ))}
          <input
            ref={inputRef}
            className="tag-input"
            value={inputValue}
            placeholder={value.length === 0 ? placeholder : ""}
            onChange={(e) => {
              setInputValue(e.target.value);
              setIsOpen(true);
              setFocusedIndex(-1);
            }}
            onFocus={() => setIsOpen(true)}
            onKeyDown={handleKeyDown}
          />
        </div>
        {isOpen && filtered.length > 0 && (
          <ul className="tag-dropdown" role="listbox">
            {filtered.map((opt, idx) => (
              <li
                key={opt.id}
                className={`tag-dropdown-item${idx === focusedIndex ? " focused" : ""}`}
                role="option"
                aria-selected={idx === focusedIndex}
                onMouseDown={(e) => {
                  e.preventDefault();
                  selectOption(opt);
                }}
                onMouseEnter={() => setFocusedIndex(idx)}
              >
                {opt.id}:{opt.name}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
