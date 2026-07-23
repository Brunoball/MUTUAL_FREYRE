import React, { useCallback, useEffect, useMemo, useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faArrowLeft,
  faBan,
  faCheck,
  faPen,
  faPlus,
  faPowerOff,
  faTrash,
  faUsers,
} from "@fortawesome/free-solid-svg-icons";
import { useNavigate } from "react-router-dom";
import CrudModal from "../../Global/components/CrudModal";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { useAuth } from "../../app/AuthProvider";
import {
  actualizarUsuarioSistema,
  cambiarEstadoUsuarioSistema,
  crearUsuarioSistema,
  eliminarUsuarioSistema,
  getUsuariosSistema,
} from "./configuracion.api";
import UsuarioSistemaModal from "./UsuarioSistemaModal";
import "./Configuracion.css";

const formatDate = (value) => {
  if (!value) return "Sin ingresos";
  const date = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return "Sin ingresos";
  return new Intl.DateTimeFormat("es-AR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
};

export default function UsuariosSistemaPage() {
  const navigate = useNavigate();
  const auth = useAuth();
  const canManage = auth.can("configuracion.manage");
  const [data, setData] = useState({ usuarios: [], roles: [], aplicacion: null });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [editor, setEditor] = useState({ open: false, user: null });
  const [confirmation, setConfirmation] = useState(null);
  const [feedback, setFeedback] = useState(null);

  const load = useCallback(async (signal) => {
    setLoading(true);
    try {
      const response = await getUsuariosSistema({ signal });
      setData(response);
    } catch (error) {
      if (error.name !== "AbortError") {
        setFeedback({ type: "error", message: error.message });
      }
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal);
    return () => controller.abort();
  }, [load]);

  const currentUserId = data.usuario_actual_id || auth.usuario?.id;
  const users = useMemo(() => data.usuarios || [], [data.usuarios]);

  const saveUser = async (payload) => {
    setSaving(true);
    try {
      const response = editor.user
        ? await actualizarUsuarioSistema(editor.user.id_acceso, payload)
        : await crearUsuarioSistema(payload);
      setEditor({ open: false, user: null });
      setFeedback({ type: "success", message: response.mensaje });

      if (response.requiere_nuevo_login) {
        try {
          await auth.logout();
        } catch {
          // La sesión ya fue revocada al cambiar la contraseña.
        }
        navigate("/", { replace: true });
        return;
      }

      await load();
      if (editor.user && Number(editor.user.id_usuario) === Number(currentUserId)) {
        await auth.refresh();
      }
    } catch (error) {
      setFeedback({ type: "error", message: error.message });
      throw error;
    } finally {
      setSaving(false);
    }
  };

  const executeConfirmation = async (event) => {
    event.preventDefault();
    if (!confirmation) return;
    setSaving(true);
    try {
      const response = confirmation.type === "delete"
        ? await eliminarUsuarioSistema(confirmation.user.id_acceso)
        : await cambiarEstadoUsuarioSistema(
            confirmation.user.id_acceso,
            !confirmation.user.acceso_activo,
          );
      setConfirmation(null);
      setFeedback({ type: "success", message: response.mensaje });
      await load();
    } catch (error) {
      setFeedback({ type: "error", message: error.message });
    } finally {
      setSaving(false);
    }
  };

  const confirmationCopy = confirmation?.type === "delete"
    ? {
        title: "Eliminar acceso",
        subtitle: "Esta acción quitará al usuario del sistema interno.",
        button: "Eliminar acceso",
        body: `¿Seguro que querés remover a ${confirmation?.user?.nombre || confirmation?.user?.usuario}? Su identidad global se conservará para futuras aplicaciones.`,
      }
    : {
        title: confirmation?.user?.acceso_activo ? "Desactivar usuario" : "Activar usuario",
        subtitle: confirmation?.user?.acceso_activo
          ? "Se cerrarán todas sus sesiones activas en este sistema."
          : "El usuario recuperará el acceso con su rol actual.",
        button: confirmation?.user?.acceso_activo ? "Desactivar" : "Activar",
        body: confirmation?.user?.acceso_activo
          ? `¿Querés desactivar el acceso de ${confirmation?.user?.nombre || confirmation?.user?.usuario}?`
          : `¿Querés activar nuevamente el acceso de ${confirmation?.user?.nombre || confirmation?.user?.usuario}?`,
      };

  return (
    <section className="users-system-page">
      <header className="users-system-page__header">
        <div className="users-system-page__title">
          <span className="users-system-page__icon">
            <FontAwesomeIcon icon={faUsers} />
          </span>
          <div>
            <span>Configuración global</span>
            <h1>Usuarios del sistema</h1>
            <p>
              Gestión de las cuentas habilitadas para {data.aplicacion?.nombre || "el sistema interno"}.
            </p>
          </div>
        </div>
        <div className="users-system-page__buttons">
          {canManage ? (
            <button
              className="configuration-button configuration-button--primary"
              onClick={() => setEditor({ open: true, user: null })}
              type="button"
            >
              <FontAwesomeIcon icon={faPlus} />
              Agregar usuario
            </button>
          ) : null}
          <button
            className="configuration-button configuration-button--ghost"
            onClick={() => navigate("/configuracion")}
            type="button"
          >
            <FontAwesomeIcon icon={faArrowLeft} />
            Volver
          </button>
        </div>
      </header>

      <article className="users-system-card">
        <div className="users-system-card__head">
          <span className="users-system-page__icon users-system-page__icon--small">
            <FontAwesomeIcon icon={faUsers} />
          </span>
          <div>
            <h2>Usuarios creados</h2>
            <p>Listado de todos los usuarios registrados en este sistema.</p>
          </div>
          <strong>{users.length} {users.length === 1 ? "usuario" : "usuarios"}</strong>
        </div>

        <div className="users-system-table-wrap">
          <table className="users-system-table">
            <thead>
              <tr>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Email</th>
                <th>Último acceso</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td className="users-system-table__message" colSpan={6}>
                    Cargando usuarios...
                  </td>
                </tr>
              ) : users.length === 0 ? (
                <tr>
                  <td className="users-system-table__message" colSpan={6}>
                    Todavía no hay usuarios registrados.
                  </td>
                </tr>
              ) : users.map((user) => {
                const isSelf = Number(user.id_usuario) === Number(currentUserId);
                return (
                  <tr className={isSelf ? "is-current-user" : ""} key={user.id_acceso}>
                    <td>
                      <div className="users-system-table__user">
                        <strong>{user.usuario}</strong>
                        <span>{user.nombre}</span>
                        {isSelf ? <small>Vos</small> : null}
                      </div>
                    </td>
                    <td>{user.rol?.nombre || "Sin rol"}</td>
                    <td>{user.email || "Sin correo"}</td>
                    <td>{formatDate(user.ultimo_acceso_en || user.ultimo_login_en)}</td>
                    <td>
                      <span className={`user-status ${user.activo ? "is-active" : "is-inactive"}`}>
                        <FontAwesomeIcon icon={user.activo ? faCheck : faBan} />
                        {user.activo ? "Activo" : "Inactivo"}
                      </span>
                    </td>
                    <td>
                      <div className="users-system-actions">
                        {canManage ? (
                          <>
                            <button
                              aria-label={`Editar ${user.usuario}`}
                              onClick={() => setEditor({ open: true, user })}
                              title="Editar usuario"
                              type="button"
                            >
                              <FontAwesomeIcon icon={faPen} />
                            </button>
                            <button
                              aria-label={`${user.acceso_activo ? "Desactivar" : "Activar"} ${user.usuario}`}
                              disabled={isSelf}
                              onClick={() => setConfirmation({ type: "status", user })}
                              title={isSelf ? "No podés cambiar tu propio estado" : user.acceso_activo ? "Desactivar" : "Activar"}
                              type="button"
                            >
                              <FontAwesomeIcon icon={faPowerOff} />
                            </button>
                            <button
                              aria-label={`Eliminar ${user.usuario}`}
                              disabled={isSelf}
                              onClick={() => setConfirmation({ type: "delete", user })}
                              title={isSelf ? "No podés eliminar tu propio acceso" : "Eliminar acceso"}
                              type="button"
                            >
                              <FontAwesomeIcon icon={faTrash} />
                            </button>
                          </>
                        ) : (
                          <span className="users-system-actions__readonly">Solo lectura</span>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </article>

      <UsuarioSistemaModal
        currentUserId={currentUserId}
        onClose={() => !saving && setEditor({ open: false, user: null })}
        onSave={saveUser}
        open={editor.open}
        roles={data.roles}
        saving={saving}
        user={editor.user}
      />

      <CrudModal
        danger={confirmation?.type === "delete" || confirmation?.user?.acceso_activo}
        onClose={() => !saving && setConfirmation(null)}
        onSubmit={executeConfirmation}
        open={Boolean(confirmation)}
        saving={saving}
        submitLabel={confirmationCopy.button}
        subtitle={confirmationCopy.subtitle}
        title={confirmationCopy.title}
      >
        <div className="user-confirmation">
          <span className="user-confirmation__icon">
            <FontAwesomeIcon icon={confirmation?.type === "delete" ? faTrash : faPowerOff} />
          </span>
          <p>{confirmationCopy.body}</p>
        </div>
      </CrudModal>

      {feedback ? (
        <ModuleFeedback
          message={feedback.message}
          onClose={() => setFeedback(null)}
          type={feedback.type}
        />
      ) : null}
    </section>
  );
}
