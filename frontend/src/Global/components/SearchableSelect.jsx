import React, { useEffect, useId, useMemo, useRef, useState } from "react";
import "./SearchableSelect.css";

const normalizeSearch = (value) =>
  String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es-AR")
    .trim();

export default function SearchableSelect({
  value,
  onChange,
  options = [],
  placeholder = "BUSCAR Y SELECCIONAR...",
  emptyMessage = "NO SE ENCONTRARON PERSONAS",
  clearLabel = "SIN SELECCIÓN",
  clearable = true,
  disabled = false,
  ariaLabel,
}) {
  const rootRef = useRef(null);
  const listboxId = useId();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [activeIndex, setActiveIndex] = useState(0);

  const selectedOption = useMemo(
    () => options.find((option) => String(option.value) === String(value ?? "")),
    [options, value],
  );

  const filteredOptions = useMemo(() => {
    const normalizedQuery = normalizeSearch(query);
    if (!normalizedQuery) return options;

    return options.filter((option) => {
      const searchableText = `${option.label ?? ""} ${option.searchText ?? ""}`;
      return normalizeSearch(searchableText).includes(normalizedQuery);
    });
  }, [options, query]);

  useEffect(() => {
    setActiveIndex(0);
  }, [query, filteredOptions.length]);

  useEffect(() => {
    if (!open) return undefined;

    const closeFromOutside = (event) => {
      if (!rootRef.current?.contains(event.target)) {
        setOpen(false);
        setQuery("");
      }
    };

    document.addEventListener("mousedown", closeFromOutside);
    return () => document.removeEventListener("mousedown", closeFromOutside);
  }, [open]);

  const openMenu = () => {
    if (disabled) return;
    if (!open) {
      setQuery("");
      setActiveIndex(0);
    }
    setOpen(true);
  };

  const selectOption = (nextValue) => {
    onChange?.(String(nextValue ?? ""));
    setOpen(false);
    setQuery("");
    setActiveIndex(0);
  };

  const onKeyDown = (event) => {
    if (disabled) return;

    if (event.key === "ArrowDown") {
      event.preventDefault();
      if (!open) {
        openMenu();
        return;
      }
      setActiveIndex((current) =>
        Math.min(current + 1, Math.max(filteredOptions.length - 1, 0)),
      );
      return;
    }

    if (event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) {
        openMenu();
        return;
      }
      setActiveIndex((current) => Math.max(current - 1, 0));
      return;
    }

    if (event.key === "Enter" && open) {
      event.preventDefault();
      const option = filteredOptions[activeIndex];
      if (option) selectOption(option.value);
      return;
    }

    if (event.key === "Escape" && open) {
      event.preventDefault();
      setOpen(false);
      setQuery("");
    }
  };

  const inputValue = open ? query : selectedOption?.label || "";

  return (
    <div
      className={`searchable-select ${open ? "is-open" : ""} ${disabled ? "is-disabled" : ""}`.trim()}
      ref={rootRef}
    >
      <div className="searchable-select__control" onMouseDown={openMenu}>
        <input
          aria-autocomplete="list"
          aria-controls={listboxId}
          aria-expanded={open}
          aria-label={ariaLabel || placeholder}
          autoComplete="off"
          disabled={disabled}
          onChange={(event) => {
            setQuery(event.target.value);
            setOpen(true);
          }}
          onFocus={openMenu}
          onKeyDown={onKeyDown}
          placeholder={placeholder}
          role="combobox"
          type="text"
          value={inputValue}
        />
        <span aria-hidden="true" className="searchable-select__chevron">⌄</span>
      </div>

      {open ? (
        <div className="searchable-select__menu" id={listboxId} role="listbox">
          {clearable ? (
            <div
              aria-selected={!value}
              className={`searchable-select__option is-clear ${!value ? "is-selected" : ""}`.trim()}
              onClick={(event) => event.preventDefault()}
              onMouseDown={(event) => {
                event.preventDefault();
                selectOption("");
              }}
              role="option"
            >
              {clearLabel}
            </div>
          ) : null}

          {filteredOptions.length ? (
            filteredOptions.map((option, index) => {
              const selected = String(option.value) === String(value ?? "");
              return (
                <div
                  aria-selected={selected}
                  className={`searchable-select__option ${selected ? "is-selected" : ""} ${index === activeIndex ? "is-active" : ""}`.trim()}
                  key={option.value}
                  onClick={(event) => event.preventDefault()}
                  onMouseDown={(event) => {
                    event.preventDefault();
                    selectOption(option.value);
                  }}
                  onMouseEnter={() => setActiveIndex(index)}
                  role="option"
                >
                  {option.label}
                </div>
              );
            })
          ) : (
            <div className="searchable-select__empty">{emptyMessage}</div>
          )}
        </div>
      ) : null}
    </div>
  );
}
