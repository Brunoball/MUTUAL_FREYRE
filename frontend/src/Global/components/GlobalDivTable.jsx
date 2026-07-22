import React, { useEffect, useRef, useState } from "react";

/**
 * Tabla global construida con divs. El encabezado queda fuera del contenedor
 * desplazable y suma el gutter únicamente cuando el cuerpo tiene scroll.
 */
export default function GlobalDivTable({
  ariaLabel,
  bodyClassName = "",
  children,
  className = "",
  columns = [],
  gridClassName = "",
}) {
  const bodyRef = useRef(null);
  const [scrollbarWidth, setScrollbarWidth] = useState(0);

  useEffect(() => {
    const body = bodyRef.current;
    if (!body) return undefined;

    let animationFrame = 0;
    const updateScrollbar = () => {
      window.cancelAnimationFrame(animationFrame);
      animationFrame = window.requestAnimationFrame(() => {
        const hasVerticalScroll = body.scrollHeight > body.clientHeight + 1;
        setScrollbarWidth(
          hasVerticalScroll ? Math.max(0, body.offsetWidth - body.clientWidth) : 0,
        );
      });
    };

    updateScrollbar();
    const resizeObserver =
      typeof ResizeObserver === "undefined"
        ? null
        : new ResizeObserver(updateScrollbar);
    const mutationObserver =
      typeof MutationObserver === "undefined"
        ? null
        : new MutationObserver(updateScrollbar);

    resizeObserver?.observe(body);
    mutationObserver?.observe(body, { childList: true, subtree: true });
    window.addEventListener("resize", updateScrollbar);

    return () => {
      window.cancelAnimationFrame(animationFrame);
      resizeObserver?.disconnect();
      mutationObserver?.disconnect();
      window.removeEventListener("resize", updateScrollbar);
    };
  }, []);

  return (
    <div
      aria-label={ariaLabel}
      className={`global-div-table ${scrollbarWidth ? "has-y-scroll" : ""} ${className}`.trim()}
      role="table"
      style={{ "--global-table-scrollbar-width": `${scrollbarWidth}px` }}
    >
      <div
        className={`global-div-table__head ${gridClassName}`.trim()}
        role="row"
      >
        {columns.map((column, index) => {
          const label = typeof column === "string" ? column : column.label;
          const columnClassName =
            typeof column === "string" ? "" : column.className || "";

          return (
            <div
              className={`global-div-table__head-cell ${columnClassName}`.trim()}
              key={typeof column === "string" ? column : column.key || index}
              role="columnheader"
            >
              {label}
            </div>
          );
        })}
      </div>

      <div
        className={`global-div-table__body ${bodyClassName}`.trim()}
        ref={bodyRef}
        role="rowgroup"
      >
        {children}
      </div>
    </div>
  );
}
