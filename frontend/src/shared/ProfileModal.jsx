import React, { useMemo } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faAt,
  faBriefcase,
  faBuilding,
  faEnvelope,
  faIdBadge,
  faShieldHalved,
  faUser,
} from "@fortawesome/free-solid-svg-icons";
import CrudModal from "../Global/components/CrudModal";
import "./ProfileModal.css";

const formatRoleList = (roles, fallback) => {
  const names = (roles || []).map((role) => role.nombre || role.codigo).filter(Boolean);
  return names.length ? names.join(", ") : fallback || "Sin rol asignado";
};

export default function ProfileModal({ open, onClose, session }) {
  const user = session?.usuario || {};
  const application = session?.aplicacion || {};
  const initials = useMemo(() => {
    const value = String(user.nombre || user.username || "U").trim();
    return value
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join("");
  }, [user.nombre, user.username]);

  const details = [
    { icon: faUser, label: "Nombre", value: user.nombre || "Sin completar" },
    { icon: faAt, label: "Usuario", value: user.username || "Sin completar" },
    { icon: faEnvelope, label: "Correo electrónico", value: user.email || "Sin completar" },
    {
      icon: faShieldHalved,
      label: "Rol",
      value: formatRoleList(user.roles, user.rol),
    },
    {
      icon: faBriefcase,
      label: "Tipo de perfil",
      value: user.tipo_perfil === "EMPLEADO" ? "Empleado interno" : user.tipo_perfil || "Sin definir",
    },
    {
      icon: faBuilding,
      label: "Sistema actual",
      value: application.nombre || application.codigo || "Sistema interno",
    },
  ];

  return (
    <CrudModal
      cancelLabel="Cerrar"
      hideSubmit
      modalClassName="profile-modal"
      onClose={onClose}
      open={open}
      subtitle="Información de la cuenta con la que ingresaste al sistema."
      title="Mi perfil"
    >
      <div className="profile-modal__summary">
        <div className="profile-modal__avatar" aria-hidden="true">
          {initials || <FontAwesomeIcon icon={faIdBadge} />}
        </div>
        <div>
          <span>Usuario autenticado</span>
          <strong>{user.nombre || user.username || "Usuario"}</strong>
          <small>{user.rol || "Acceso al sistema interno"}</small>
        </div>
        <span className="profile-modal__status">Activo</span>
      </div>

      <div className="profile-modal__details">
        {details.map((detail) => (
          <div className="profile-modal__detail" key={detail.label}>
            <span className="profile-modal__detail-icon">
              <FontAwesomeIcon icon={detail.icon} />
            </span>
            <div>
              <span>{detail.label}</span>
              <strong>{detail.value}</strong>
            </div>
          </div>
        ))}
      </div>

      <div className="profile-modal__notice">
        Los permisos se aplican según el rol asignado en Usuarios del sistema.
      </div>
    </CrudModal>
  );
}
