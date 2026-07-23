import React from "react";
import GlobalIcon from "./GlobalIcon";

const tabId = (prefix, value) =>
  `${prefix}-${String(value).replace(/[^a-zA-Z0-9_-]/g, "-")}`;

export function EntityTabs({
  tabs,
  value,
  onChange,
  idPrefix = "entity-tab",
  ariaLabel = "Secciones del formulario",
  className = "",
}) {
  const moveFocus = (nextIndex) => {
    const nextTab = tabs[nextIndex];
    if (!nextTab) return;
    onChange(nextTab.value);
    window.requestAnimationFrame(() => {
      document.getElementById(tabId(idPrefix, nextTab.value))?.focus();
    });
  };

  const handleKeyDown = (event, index) => {
    if (event.key === "ArrowRight") {
      event.preventDefault();
      moveFocus((index + 1) % tabs.length);
    } else if (event.key === "ArrowLeft") {
      event.preventDefault();
      moveFocus((index - 1 + tabs.length) % tabs.length);
    } else if (event.key === "Home") {
      event.preventDefault();
      moveFocus(0);
    } else if (event.key === "End") {
      event.preventDefault();
      moveFocus(tabs.length - 1);
    }
  };

  return (
    <div
      className={`entity-form-tabs ${className}`.trim()}
      role="tablist"
      aria-label={ariaLabel}
    >
      {tabs.map((tab, index) => {
        const selected = String(value) === String(tab.value);
        return (
          <button
            aria-controls={`${tabId(idPrefix, tab.value)}-panel`}
            aria-selected={selected}
            className={`entity-form-tab ${selected ? "is-active" : ""}`}
            id={tabId(idPrefix, tab.value)}
            key={tab.value}
            onClick={() => onChange(tab.value)}
            onKeyDown={(event) => handleKeyDown(event, index)}
            role="tab"
            tabIndex={selected ? 0 : -1}
            type="button"
          >
            {tab.icon ? <GlobalIcon name={tab.icon} size={15} /> : null}
            <span>{tab.label}</span>
            {tab.badge !== undefined &&
            tab.badge !== null &&
            tab.badge !== "" ? (
              <span className="entity-form-tab__badge">{tab.badge}</span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}

export function EntityFormPanel({
  tabValue,
  idPrefix = "entity-tab",
  eyebrow,
  title,
  tag,
  children,
  hint,
  className = "",
  bodyClassName = "",
}) {
  const id = tabId(idPrefix, tabValue);
  return (
    <section
      aria-labelledby={id}
      className={`entity-form-panel ${className}`.trim()}
      id={`${id}-panel`}
      role="tabpanel"
    >
      {eyebrow || title || tag ? (
        <header className="entity-form-panel__header">
          <div>
            {eyebrow ? <span>{eyebrow}</span> : null}
            {title ? <h3>{title}</h3> : null}
          </div>
          {tag ? <small>{tag}</small> : null}
        </header>
      ) : null}
      <div className={`entity-form-panel__body ${bodyClassName}`.trim()}>
        {children}
      </div>
      {hint ? <p className="entity-form-panel__hint">{hint}</p> : null}
    </section>
  );
}
