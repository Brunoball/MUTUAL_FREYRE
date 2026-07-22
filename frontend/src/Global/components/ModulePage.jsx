import React from "react";
import GlobalIcon from "./GlobalIcon";

const optionValue = (option) =>
  typeof option === "object" ? option.value : option;
const optionLabel = (option) =>
  typeof option === "object" ? option.label : option;

function ModuleTitleTabs({ filter }) {
  const value = filter.value ?? "";

  return (
    <div
      aria-label={filter.ariaLabel || filter.label}
      className="global-module-title-tabs"
      role="tablist"
    >
      {(filter.options || []).map((option) => {
        const nextValue = optionValue(option);
        const selected = String(value) === String(nextValue);

        return (
          <button
            aria-selected={selected}
            className={`global-module-title-tab ${selected ? "is-active" : ""}`}
            key={nextValue}
            onClick={() => filter.onChange?.(nextValue)}
            role="tab"
            type="button"
          >
            {optionLabel(option)}
          </button>
        );
      })}
    </div>
  );
}

function ModuleFilter({ filter }) {
  const value = filter.value ?? "";
  const active = filter.type !== "search" || String(value).trim() !== "";

  return (
    <label
      className={`global-module-filter global-module-filter--${filter.type || "search"} ${active ? "is-active" : ""} ${filter.className || ""}`.trim()}
    >
      {filter.type === "select" ? (
        <select
          aria-label={filter.label}
          className="global-module-filter__control"
          onChange={(event) => filter.onChange?.(event.target.value)}
          value={value}
        >
          {filter.includeEmptyOption !== false ? (
            <option value="">{filter.placeholder || "Todos"}</option>
          ) : null}
          {(filter.options || []).map((option) => (
            <option key={optionValue(option)} value={optionValue(option)}>
              {optionLabel(option)}
            </option>
          ))}
        </select>
      ) : (
        <>
          <input
            aria-label={filter.label}
            className="global-module-filter__control global-module-filter__control--search"
            onChange={(event) => filter.onChange?.(event.target.value)}
            placeholder={filter.placeholder || "Buscar..."}
            type="search"
            value={value}
          />
          {String(value).trim() ? (
            <button
              aria-label="Limpiar búsqueda"
              className="global-module-filter__clear"
              onClick={() => filter.onChange?.("")}
              title="Limpiar búsqueda"
              type="button"
            >
              <GlobalIcon name="close" size={14} />
            </button>
          ) : null}
        </>
      )}
      <span className="global-module-filter__label">
        {filter.type === "search" ? <GlobalIcon name="search" size={14} /> : null}
        {filter.label}
      </span>
    </label>
  );
}

function ActionIcon({ icon }) {
  if (!icon) return null;
  return typeof icon === "string" ? <GlobalIcon name={icon} size={16} /> : icon;
}

export function ModulePage({
  title,
  description,
  filters = [],
  primaryActionLabel = "Nuevo registro",
  onPrimaryAction,
  onRefresh,
  secondaryActions = [],
  canCreate = true,
  refreshing = false,
  tabsInTitle = false,
  children,
  notice,
}) {
  const titleTabs = tabsInTitle
    ? filters.find((filter) => filter.type === "tabs")
    : null;
  const headerFilters = titleTabs
    ? filters.filter((filter) => filter !== titleTabs)
    : filters;

  return (
    <section className="global-module-page">
      <article className="global-module-card">
        <header className="global-module-card__head">
          <div className="global-module-card__head-left">
            <div className="global-module-title-box">
              <h1>{title}</h1>
              {titleTabs ? (
                <ModuleTitleTabs filter={titleTabs} />
              ) : description ? (
                <p>{description}</p>
              ) : null}
            </div>

            {headerFilters.length ? (
              <div className="global-module-filters">
                {headerFilters.map((filter) => (
                  <ModuleFilter
                    filter={filter}
                    key={filter.key || filter.label}
                  />
                ))}
              </div>
            ) : null}
          </div>

          <div className="global-module-card__actions">
            {secondaryActions.map((action) => (
              <button
                className={`global-button ${action.className || "global-button--ghost"}`}
                disabled={action.disabled}
                key={action.key || action.label}
                onClick={action.onClick}
                title={action.title}
                type="button"
              >
                <ActionIcon icon={action.icon} />
                {action.label}
              </button>
            ))}
            {onRefresh ? (
              <button
                className="global-button global-button--ghost"
                disabled={refreshing}
                onClick={onRefresh}
                type="button"
              >
                <GlobalIcon
                  className={refreshing ? "is-spinning" : ""}
                  name="refresh"
                  size={16}
                />
                {refreshing ? "Actualizando..." : "Actualizar"}
              </button>
            ) : null}
            {canCreate ? (
              <button
                className="global-button global-button--primary"
                disabled={!onPrimaryAction}
                onClick={onPrimaryAction}
                type="button"
              >
                <GlobalIcon name="plus" size={17} />
                {primaryActionLabel}
              </button>
            ) : null}
          </div>
        </header>

        {notice ? <div className="global-module-notice">{notice}</div> : null}
        {children}
      </article>
    </section>
  );
}
