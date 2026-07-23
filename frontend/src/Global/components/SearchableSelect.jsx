import React, {
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import GlobalIcon from "./GlobalIcon";

const normalizeText = (value) =>
  String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es-AR")
    .trim();

const optionValue = (option) =>
  option?.value ?? option?.id ?? option?.codigo ?? "";

const optionLabel = (option) =>
  String(
    option?.label ??
      option?.nombre ??
      option?.descripcion ??
      option?.texto ??
      optionValue(option),
  ).trim();

export default function SearchableSelect({
  ariaLabel = "Buscar una opción",
  className = "",
  clearLabel = "SIN SELECCIÓN",
  disabled = false,
  emptyMessage = "NO SE ENCONTRARON RESULTADOS",
  id,
  maxResults = 80,
  name,
  onBlur,
  onChange,
  onFocus,
  options = [],
  placeholder = "BUSCAR...",
  value = "",
}) {
  const rootRef = useRef(null);
  const inputRef = useRef(null);
  const optionRefs = useRef([]);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [activeIndex, setActiveIndex] = useState(-1);

  const normalizedOptions = useMemo(
    () =>
      (Array.isArray(options) ? options : [])
        .filter((option) => option && typeof option === "object")
        .map((option) => ({
          original: option,
          label: optionLabel(option),
          value: optionValue(option),
        }))
        .filter((option) => option.label),
    [options],
  );

  const selectedOption = useMemo(
    () =>
      normalizedOptions.find(
        (option) => String(option.value) === String(value ?? ""),
      ) || null,
    [normalizedOptions, value],
  );

  const filteredOptions = useMemo(() => {
    const normalizedQuery = normalizeText(query);
    const filtered = normalizedQuery
      ? normalizedOptions.filter((option) =>
          normalizeText(option.label).includes(normalizedQuery),
        )
      : normalizedOptions;

    return filtered.slice(0, Math.max(Number(maxResults) || 1, 1));
  }, [maxResults, normalizedOptions, query]);

  useEffect(() => {
    if (!open) return undefined;

    const closeFromOutside = (event) => {
      if (rootRef.current?.contains(event.target)) return;
      setOpen(false);
      setQuery("");
      setActiveIndex(-1);
      onBlur?.(event);
    };

    document.addEventListener("mousedown", closeFromOutside, true);
    document.addEventListener("touchstart", closeFromOutside, true);
    return () => {
      document.removeEventListener("mousedown", closeFromOutside, true);
      document.removeEventListener("touchstart", closeFromOutside, true);
    };
  }, [onBlur, open]);

  useEffect(() => {
    if (!open || activeIndex < 0) return;
    optionRefs.current[activeIndex]?.scrollIntoView?.({
      block: "nearest",
    });
  }, [activeIndex, open]);

  useEffect(() => {
    if (disabled) {
      setOpen(false);
      setQuery("");
      setActiveIndex(-1);
    }
  }, [disabled]);

  const openList = (event) => {
    if (disabled) return;
    setOpen(true);
    setQuery("");
    setActiveIndex(-1);
    onFocus?.(event);
  };

  const closeList = () => {
    setOpen(false);
    setQuery("");
    setActiveIndex(-1);
  };

  const selectOption = (option) => {
    onChange?.(option.value, option.original);
    closeList();
    window.setTimeout(() => inputRef.current?.focus(), 0);
  };

  const clearSelection = (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (disabled) return;
    onChange?.("", null);
    setQuery("");
    setOpen(true);
    setActiveIndex(-1);
    window.setTimeout(() => inputRef.current?.focus(), 0);
  };

  const handleKeyDown = (event) => {
    if (disabled) return;

    if (event.key === "ArrowDown") {
      event.preventDefault();
      if (!open) {
        setOpen(true);
        setQuery("");
      }
      setActiveIndex((current) =>
        filteredOptions.length
          ? Math.min(current + 1, filteredOptions.length - 1)
          : -1,
      );
      return;
    }

    if (event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) {
        setOpen(true);
        setQuery("");
      }
      setActiveIndex((current) =>
        filteredOptions.length
          ? current <= 0
            ? filteredOptions.length - 1
            : current - 1
          : -1,
      );
      return;
    }

    if (event.key === "Enter") {
      event.preventDefault();
      if (!open) {
        setOpen(true);
        setQuery("");
        return;
      }
      const option = filteredOptions[activeIndex];
      if (option) selectOption(option);
      return;
    }

    if (event.key === "Escape") {
      if (!open) return;
      event.preventDefault();
      event.stopPropagation();
      closeList();
      return;
    }

    if (event.key === "Tab") closeList();
  };

  const inputValue = open ? query : selectedOption?.label || "";
  const listboxId = id ? `${id}-options` : undefined;

  return (
    <div
      className={`global-searchable-select ${open ? "is-open" : ""} ${
        disabled ? "is-disabled" : ""
      } ${className}`.trim()}
      ref={rootRef}
    >
      <div className="global-searchable-select__control">
        <GlobalIcon
          className="global-searchable-select__search-icon"
          name="search"
          size={17}
        />
        <input
          aria-autocomplete="list"
          aria-controls={listboxId}
          aria-expanded={open}
          aria-label={ariaLabel}
          autoComplete="off"
          className="global-searchable-select__input"
          disabled={disabled}
          id={id}
          name={name}
          onChange={(event) => {
            setQuery(event.target.value);
            setOpen(true);
            setActiveIndex(-1);
          }}
          onClick={openList}
          onFocus={openList}
          onKeyDown={handleKeyDown}
          placeholder={open ? placeholder : ""}
          ref={inputRef}
          role="combobox"
          value={inputValue}
        />

        {(selectedOption || query) && !disabled ? (
          <button
            aria-label={clearLabel}
            className="global-searchable-select__clear"
            onClick={clearSelection}
            title={clearLabel}
            type="button"
          >
            <GlobalIcon name="close" size={14} />
          </button>
        ) : (
          <span
            aria-hidden="true"
            className="global-searchable-select__indicator"
          />
        )}
      </div>

      {open ? (
        <div
          aria-label={ariaLabel}
          className="global-searchable-select__menu"
          id={listboxId}
          role="listbox"
        >
          <button
            aria-selected={String(value ?? "") === ""}
            className={`global-searchable-select__option global-searchable-select__option--clear ${
              String(value ?? "") === "" ? "is-selected" : ""
            }`.trim()}
            onMouseDown={(event) => event.preventDefault()}
            onClick={clearSelection}
            role="option"
            type="button"
          >
            {clearLabel}
          </button>

          {filteredOptions.length ? (
            filteredOptions.map((option, index) => {
              const selected =
                String(option.value) === String(value ?? "");
              return (
                <button
                  aria-selected={selected}
                  className={`global-searchable-select__option ${
                    selected ? "is-selected" : ""
                  } ${activeIndex === index ? "is-active" : ""}`.trim()}
                  key={`${String(option.value)}-${option.label}`}
                  onClick={() => selectOption(option)}
                  onMouseDown={(event) => event.preventDefault()}
                  onMouseEnter={() => setActiveIndex(index)}
                  ref={(element) => {
                    optionRefs.current[index] = element;
                  }}
                  role="option"
                  title={option.label}
                  type="button"
                >
                  <span>{option.label}</span>
                  {selected ? <GlobalIcon name="check" size={16} /> : null}
                </button>
              );
            })
          ) : (
            <div className="global-searchable-select__empty">
              <GlobalIcon name="inbox" size={18} />
              <span>{emptyMessage}</span>
            </div>
          )}
        </div>
      ) : null}
    </div>
  );
}
