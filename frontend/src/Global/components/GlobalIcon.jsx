import React from "react";

const ICONS = {
  search: (
    <>
      <circle cx="11" cy="11" r="6.5" />
      <path d="m16 16 4 4" />
    </>
  ),
  close: (
    <>
      <path d="m6 6 12 12" />
      <path d="M18 6 6 18" />
    </>
  ),
  plus: (
    <>
      <path d="M12 5v14" />
      <path d="M5 12h14" />
    </>
  ),
  refresh: (
    <>
      <path d="M20 7v5h-5" />
      <path d="M18.2 16.2A8 8 0 1 1 20 12" />
    </>
  ),
  eye: (
    <>
      <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
      <circle cx="12" cy="12" r="2.6" />
    </>
  ),
  edit: (
    <>
      <path d="m4 20 4.2-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20Z" />
      <path d="m13.8 7.2 3 3" />
    </>
  ),
  disable: (
    <>
      <circle cx="9" cy="8" r="3.3" />
      <path d="M3.5 18c.5-3 2.4-4.8 5.5-4.8 1 0 1.8.2 2.5.5" />
      <circle cx="17" cy="17" r="4" />
      <path d="m14.2 14.2 5.6 5.6" />
    </>
  ),
  enable: (
    <>
      <circle cx="9" cy="8" r="3.3" />
      <path d="M3.5 18c.5-3 2.4-4.8 5.5-4.8 1 0 1.8.2 2.5.5" />
      <circle cx="17" cy="17" r="4" />
      <path d="M17 14.8v4.4M14.8 17h4.4" />
    </>
  ),
  check: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12 2.6 2.7L16.5 9" />
    </>
  ),
  error: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m8.7 8.7 6.6 6.6M15.3 8.7l-6.6 6.6" />
    </>
  ),
  warning: (
    <>
      <path d="M10.3 4.3 2.7 18a2 2 0 0 0 1.8 3h15a2 2 0 0 0 1.8-3L13.7 4.3a2 2 0 0 0-3.4 0Z" />
      <path d="M12 9v4" />
      <path d="M12 17h.01" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 10v6" />
      <path d="M12 7h.01" />
    </>
  ),
  loader: (
    <>
      <path d="M21 12a9 9 0 0 1-9 9" />
      <path d="M3 12a9 9 0 0 1 9-9" />
    </>
  ),
  inbox: (
    <>
      <path d="M4 5h16v14H4z" />
      <path d="M4 14h4l2 2h4l2-2h4" />
    </>
  ),
};

export default function GlobalIcon({
  name,
  size = 18,
  className = "",
  ...props
}) {
  return (
    <svg
      aria-hidden="true"
      className={`global-icon ${className}`.trim()}
      fill="none"
      height={size}
      viewBox="0 0 24 24"
      width={size}
      stroke="currentColor"
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth="1.8"
      {...props}
    >
      {ICONS[name] || ICONS.info}
    </svg>
  );
}
